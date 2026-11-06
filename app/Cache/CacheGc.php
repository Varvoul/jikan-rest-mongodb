<?php

/**
 * Dynamic MongoDB cache garbage collector for the free-tier (M0, 512MB) cluster.
 *
 * Two layers of self-healing, both safe to run at any time:
 *
 * 1. RETENTION SWEEP — deletes payload/TTL docs nobody touched for N days
 *    (LRU semantics: entries still being requested get refreshed, so their
 *    updated_at stays recent and they survive; cold entries age out).
 * 2. SIZE GUARD — if the cache collection's logical size crosses the HIGH
 *    watermark, oldest-first batch eviction until back under the TARGET
 *    watermark. Guarantees the cluster never reaches Atlas' hard storage cap
 *    (which blocks all writes and takes the whole API down).
 *
 * Triggered opportunistically from MongoDbStore::put() (sampled + throttled
 * via a state doc in the same collection), from the artisan command
 * `jikan:cache-gc`, and from the /v4/meta/cache_gc endpoint. Never throws:
 * a GC failure must never break a cache write.
 */

namespace App\Cache;

use MongoDB\Collection;
use MongoDB\BSON\UTCDateTime;

class CacheGc
{
    /** state doc key suffix inside the cache collection (excluded from all sweeps) */
    private const STATE_SUFFIX = 'gcstate';

    /**
     * @var Collection
     */
    private $collection;

    /**
     * @var string cache prefix (e.g. "jikan")
     */
    private $prefix;

    /**
     * @var int logical-size HIGH watermark (bytes above which eviction starts)
     */
    private $highBytes;

    /**
     * @var int logical-size TARGET after eviction
     */
    private $targetBytes;

    /**
     * @var int seconds a payload/ttl doc may go untouched
     */
    private $retentionSeconds;

    /**
     * @var int min seconds between automatic runs
     */
    private $minInterval;

    /**
     * @var int max seconds a single run may spend deleting
     */
    private $timeBudget;

    public function __construct(Collection $collection, string $prefix = 'jikan')
    {
        $this->collection = $collection;
        $this->prefix = $prefix;
        $this->highBytes = max(64, (int) env('CACHE_GC_HIGH_MB', 350)) * 1024 * 1024;
        $this->targetBytes = min(
            max(32, (int) env('CACHE_GC_TARGET_MB', 220)),
            max(64, (int) env('CACHE_GC_HIGH_MB', 350)) - 16
        ) * 1024 * 1024;
        $this->retentionSeconds = max(1, (int) env('CACHE_GC_RETENTION_DAYS', 7)) * 86400;
        $this->minInterval = max(60, (int) env('CACHE_GC_MIN_INTERVAL', 900));
        $this->timeBudget = max(2, (int) env('CACHE_GC_TIME_BUDGET', 8));
    }

    /**
     * Run the collector if it is due. Safe to call on every cache write.
     *
     * @param bool $force bypass the min-interval throttle (admin/command use)
     * @return array|null run report, or null when skipped
     */
    public function maybeRun(bool $force = false)
    {
        try {
            $now = time();

            if (!$force) {
                $state = $this->readState();
                $lastRun = (int) ($state['last_run'] ?? 0);
                if ($now - $lastRun < $this->minInterval) {
                    return null;
                }
            }

            $report = $this->run($now);
            $this->writeState($report, $now);
            return $report;
        } catch (\Throwable $e) {
            try {
                $this->writeState(['error' => $e->getMessage()], time());
            } catch (\Throwable $ignored) {
                // state write failing too — nothing more we can do here
            }
            return null;
        }
    }

    /**
     * One collection pass: retention sweep + size guard.
     *
     * @return array report
     */
    public function run(int $now)
    {
        $startedAt = microtime(true);
        $deadline = $startedAt + $this->timeBudget;

        // index for updated_at scans (idempotent; without it every sweep is a
        // collection scan and eviction sorts cannot use an index)
        try {
            $this->collection->createIndex(['updated_at' => 1], ['background' => true]);
        } catch (\Throwable $ignored) {
            // index may already exist or creation may be throttled — sweeps still work
        }

        $before = $this->measure();

        // --- layer 1: retention sweep (LRU by updated_at) ---
        $retentionCutoff = new UTCDateTime(($now - $this->retentionSeconds) * 1000);
        $deletedRetention = $this->deleteBatches(
            ['key' => $this->dataFamilyRegex(), 'updated_at' => ['$lt' => $retentionCutoff]],
            $deadline
        );

        // --- layer 2: size guard (oldest first) ---
        $deletedEviction = 0;
        $afterRetention = $this->measure();
        if ($afterRetention['logical_bytes'] > $this->highBytes) {
            $deletedEviction = $this->evictOldest(
                $afterRetention['logical_bytes'] - $this->targetBytes,
                $deadline
            );
        }

        $after = $this->measure();

        return [
            'ran_at' => gmdate('c', $now),
            'duration_s' => round(microtime(true) - $startedAt, 2),
            'before_mb' => round($before['logical_bytes'] / 1048576, 1),
            'after_mb' => round($after['logical_bytes'] / 1048576, 1),
            'docs_before' => $before['count'],
            'docs_after' => $after['count'],
            'deleted_retention' => $deletedRetention,
            'deleted_eviction' => $deletedEviction,
            'high_mb' => (int) ($this->highBytes / 1048576),
            'target_mb' => (int) ($this->targetBytes / 1048576),
            'retention_days' => (int) ($this->retentionSeconds / 86400),
        ];
    }

    /**
     * Evict oldest docs (by updated_at) until roughly $bytesToFree of logical
     * size is freed or the time budget is spent.
     *
     * @return int deleted count
     */
    private function evictOldest(int $bytesToFree, float $deadline)
    {
        $deleted = 0;
        $batchSize = 2000;

        while ($bytesToFree > 0 && microtime(true) < $deadline) {
            $docs = $this->collection->aggregate([
                ['$match' => ['key' => $this->dataFamilyRegex()]],
                ['$sort' => ['updated_at' => 1]],
                ['$limit' => $batchSize],
                ['$project' => [
                    'vlen' => ['$strLenCP' => [
                        '$convert' => ['input' => '$value', 'to' => 'string', 'onError' => ''],
                    ]],
                    'klen' => ['$strLenCP' => [
                        '$convert' => ['input' => '$key', 'to' => 'string', 'onError' => ''],
                    ]],
                ]],
            ])->toArray();

            if (count($docs) === 0) {
                break;
            }

            $bytes = 0;
            $ids = [];
            foreach ($docs as $doc) {
                $ids[] = $doc['_id'];
                // rough logical footprint: value + key + BSON/WiredTiger overhead
                $bytes += (int) ($doc['vlen'] ?? 0) + (int) ($doc['klen'] ?? 0) + 256;
            }

            $result = $this->collection->deleteMany(['_id' => ['$in' => $ids]]);
            $deleted += $result->getDeletedCount();
            $bytesToFree -= $bytes;
        }

        return $deleted;
    }

    /**
     * Delete matching docs in batches until the filter matches nothing or the
     * time budget is spent.
     *
     * @return int deleted count
     */
    private function deleteBatches(array $filter, float $deadline)
    {
        $deleted = 0;
        while (microtime(true) < $deadline) {
            $ids = [];
            foreach ($this->collection->find($filter, ['projection' => ['_id' => 1], 'limit' => 5000]) as $doc) {
                $ids[] = $doc['_id'];
            }
            if (count($ids) === 0) {
                break;
            }
            $result = $this->collection->deleteMany(['_id' => ['$in' => $ids]]);
            $deleted += $result->getDeletedCount();
            if ($result->getDeletedCount() < count($ids)) {
                break; // something is racing us; the next trigger continues
            }
        }
        return $deleted;
    }

    /**
     * Logical size + doc count of the cache collection ($collStats).
     *
     * @return array
     */
    private function measure()
    {
        $out = $this->collection->aggregate([['$collStats' => ['storageStats' => []]]])->toArray();
        $storage = isset($out[0]['storageStats']) ? (array) $out[0]['storageStats'] : [];
        return [
            'logical_bytes' => (int) ($storage['size'] ?? 0),
            'count' => (int) ($storage['count'] ?? 0),
        ];
    }

    /**
     * Regex matching payload + ttl companion docs only — NOT request log
     * entries (they expire on their own) and NOT the GC state doc.
     *
     * @return array
     */
    private function dataFamilyRegex()
    {
        $p = preg_quote($this->prefix, '/');
        return ['$regex' => "^({$p}request:|{$p}ttl:)"];
    }

    /**
     * @return array|null
     */
    private function readState()
    {
        $doc = $this->collection->findOne(['key' => $this->stateKey()]);
        return $doc === null ? null : (array) $doc;
    }

    /**
     * @param array $report
     */
    private function writeState(array $report, int $now)
    {
        $report['last_run'] = $now;
        $this->collection->updateOne(
            ['key' => $this->stateKey()],
            ['$set' => [
                'key' => $this->stateKey(),
                'value' => json_encode($report),
                'updated_at' => new UTCDateTime($now * 1000),
                // self-expiring so the state doc can never litter the cluster
                'expires_at' => new UTCDateTime(($now + 30 * 86400) * 1000),
            ]],
            ['upsert' => true]
        );
    }

    /**
     * @return string
     */
    private function stateKey()
    {
        return $this->prefix . self::STATE_SUFFIX;
    }
}
