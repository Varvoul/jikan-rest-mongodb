<?php

namespace App\Http\Controllers\V3;

use Illuminate\Support\Facades\Cache;
use MongoDB\Client;

class MetaController extends Controller
{
    public function status()
    {
        try {
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $cacheCollection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            $totalCached = $cacheCollection->countDocuments([]);
            $todayPrefix = env('CACHE_PREFIX', 'jikan') . 'requests:today:';
            $weeklyPrefix = env('CACHE_PREFIX', 'jikan') . 'requests:weekly:';
            $monthlyPrefix = env('CACHE_PREFIX', 'jikan') . 'requests:monthly:';

            return response()->json([
                'cached_requests' => $totalCached,
                'requests_today' => $cacheCollection->countDocuments(['key' => ['$regex' => '^' . preg_quote($todayPrefix, '/')]]),
                'requests_this_week' => $cacheCollection->countDocuments(['key' => ['$regex' => '^' . preg_quote($weeklyPrefix, '/')]]),
                'requests_this_month' => $cacheCollection->countDocuments(['key' => ['$regex' => '^' . preg_quote($monthlyPrefix, '/')]]),
                'cache_driver' => 'mongodb',
                'database' => $database,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'cached_requests' => 0,
                'requests_today' => 0,
                'requests_this_week' => 0,
                'requests_this_month' => 0,
                'cache_driver' => 'mongodb',
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function clearCache()
    {
        try {
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $collection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            // Delete all cached request data (request:* and ttl:*) but keep meta counters
            $dataResult = $collection->deleteMany(['key' => ['$regex' => '^' . preg_quote(env('CACHE_PREFIX', 'jikan') . 'request:', '/')]]);
            $ttlResult = $collection->deleteMany(['key' => ['$regex' => '^' . preg_quote(env('CACHE_PREFIX', 'jikan') . 'ttl:', '/')]]);
            $notFoundResult = $collection->deleteMany(['key' => ['$regex' => '^' . preg_quote(env('CACHE_PREFIX', 'jikan') . 'request:404:', '/')]]);

            return response()->json([
                'status' => 'ok',
                'deleted_data_entries' => $dataResult->getDeletedCount(),
                'deleted_ttl_entries' => $ttlResult->getDeletedCount(),
                'deleted_404_entries' => $notFoundResult->getDeletedCount(),
                'message' => 'All request cache cleared. Next requests will re-fetch from MAL.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear ONLY seasonal/schedule cache entries
     * This allows refreshing currently airing & upcoming anime data
     * without clearing the entire cache
     */
    public function clearSeasonalCache()
    {
        try {
            $prefix = env('CACHE_PREFIX', 'jikan');
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $collection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            // Patterns for seasonal/schedule related cache keys
            $patterns = [
                // Season endpoints
                'season$',                    // /season (current)
                'season/later$',              // /season/later (upcoming)
                'season/20[0-9]{2}/',        // /season/YYYY/SEASON
                'season/archive$',           // /season/archive
                // Schedule endpoint
                'schedule$',                  // /schedule
                'schedule/[a-z]+$',          // /schedule/{day}
            ];

            $totalDeleted = 0;
            $details = [];

            foreach ($patterns as $pattern) {
                $regex = '^' . preg_quote($prefix . 'request:', '/') . '.*' . $pattern;
                $result = $collection->deleteMany(['key' => ['$regex' => $regex]]);
                $count = $result->getDeletedCount();
                $totalDeleted += $count;
                if ($count > 0) {
                    $details[] = "{$pattern}: {$count} entries";
                }
            }

            // Also clear TTL entries for seasonal data
            foreach ($patterns as $pattern) {
                $regex = '^' . preg_quote($prefix . 'ttl:', '/') . '.*' . $pattern;
                $result = $collection->deleteMany(['key' => ['$regex' => $regex]]);
                $totalDeleted += $result->getDeletedCount();
            }

            return response()->json([
                'status' => 'ok',
                'message' => 'Seasonal & schedule cache cleared successfully. Next request to /v4/season, /v4/season/later, or /v4/schedule will fetch fresh data from MAL.',
                'deleted_entries' => $totalDeleted,
                'cleared_patterns' => [
                    '/v4/season (currently airing)',
                    '/v4/season/later (upcoming)', 
                    '/v4/season/{year}/{season} (specific season)',
                    '/v4/season/archive',
                    '/v4/schedule (weekly schedule)',
                    '/v4/schedule/{day} (daily schedule)'
                ],
                'details' => $details,
                'timestamp' => date('c'),
                'next_step' => 'Call GET /v4/season or GET /v4/season/later to fetch fresh data'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get seasonal cache status - check if seasonal data is cached and how old it is
     */
    public function seasonalCacheStatus()
    {
        try {
            $prefix = env('CACHE_PREFIX', 'jikan');
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $collection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            $endpoints = [
                'current_season' => ['pattern' => 'season$', 'name' => 'Currently Airing (/v4/season)'],
                'upcoming' => ['pattern' => 'season/later$', 'name' => 'Upcoming (/v4/season/later)'],
                'schedule' => ['pattern' => 'schedule$', 'name' => 'Weekly Schedule (/v4/schedule)'],
                'archive' => ['pattern' => 'season/archive$', 'name' => 'Season Archive (/v4/season/archive)'],
            ];

            $status = [];

            foreach ($endpoints as $key => $endpoint) {
                $regex = '^' . preg_quote($prefix . 'request:', '/') . '.*' . $endpoint['pattern'];
                $cursor = $collection->find(
                    ['key' => ['$regex' => $regex]],
                    ['limit' => 1, 'sort' => ['_id' => -1]]
                );

                $doc = iterator_to_array($cursor)[0] ?? null;

                if ($doc && isset($doc['expires_at'])) {
                    $expiresAt = $doc['expires_at'] instanceof \MongoDB\BSON\UTCDateTime 
                        ? $doc['expires_at']->toDateTime()->format('U')
                        : strtotime((string) $doc['expires_at']);
                    $now = time();
                    $ageSeconds = $now - (isset($doc['created_at']) ? (
                        $doc['created_at'] instanceof \MongoDB\BSON\UTCDateTime 
                            ? $doc['created_at']->toDateTime()->format('U')
                            : strtotime((string) $doc['created_at'])
                    ) : $now);
                    $ttlRemaining = max(0, $expiresAt - $now);

                    $status[$key] = [
                        'name' => $endpoint['name'],
                        'cached' => true,
                        'age_minutes' => round($ageSeconds / 60, 1),
                        'ttl_remaining_minutes' => round($ttlRemaining / 60, 1),
                        'expires_at' => date('Y-m-d H:i:s T', $expiresAt),
                        'cache_key' => $doc['key'],
                    ];
                } else {
                    $status[$key] = [
                        'name' => $endpoint['name'],
                        'cached' => false,
                        'message' => 'Not cached yet - will fetch from MAL on next request',
                    ];
                }
            }

            return response()->json([
                'status' => 'ok',
                'seasonal_cache_status' => $status,
                'note' => 'Use POST /meta/clear_seasonal_cache to force refresh these endpoints',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /meta/clear_anime_total_cache
     *
     * Clears the cached anime total count, forcing recalculation
     * on next /v4/anime request. This is useful after updating
     * the ESTIMATED_MAX_MAL_ID or when MAL adds many new anime.
     */
    public function clearAnimeTotalCache()
    {
        try {
            // Clear from Laravel Cache (uses MongoDB)
            Cache::forget('anime_list_total_count');

            // Also try to clear from MongoDB directly for safety
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $cacheCollection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            $cacheKey = env('CACHE_PREFIX', 'jikan') . ':cache:anime_list_total_count';
            $result = $cacheCollection->deleteOne(['key' => $cacheKey]);

            return response()->json([
                'status' => 'ok',
                'message' => 'Anime total cache cleared successfully. Next /v4/anime request will recalculate the total.',
                'deleted_from_mongo' => $result->getDeletedCount(),
                'note' => 'New total will be estimated by checking recent MAL anime IDs (up to 65,000+)',
                'timestamp' => date('c'),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function requests($type, $period, $offset = 0)
    {
        if (!\in_array($type, [
            'anime', 'manga', 'character', 'person', 'people', 'search', 'top', 'season', 'schedule', 'user', 'producer', 'magazine', 'genre'
        ])) {
            return response()->json([
                'error' => 'Bad Request'
            ], 400);
        }

        if (!\in_array($period, ['today', 'weekly', 'monthly'])) {
            return response()->json([
                'error' => 'Bad Request'
            ], 400);
        }

        $requests = [];
        try {
            $uri = env('MONGODB_URI');
            $database = env('MONGODB_DATABASE', 'jikan');
            $client = new Client($uri);
            $db = $client->selectDatabase($database);
            $collection = $db->selectCollection(env('MONGODB_CACHE_COLLECTION', 'cache'));

            $prefix = env('CACHE_PREFIX', 'jikan') . "requests:{$period}:";
            $regex = '^' . preg_quote($prefix, '/') . '.*' . preg_quote($type, '/') . '.*';
            $cursor = $collection->find(
                ['key' => ['$regex' => $regex]],
                ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]
            );

            foreach ($cursor as $doc) {
                if (isset($doc['key'])) {
                    $parts = explode(':', str_replace(env('CACHE_PREFIX', 'jikan'), '', $doc['key']));
                    // parts: [requests, period, uri_path]
                    $uriPart = $parts[2] ?? '';
                    $requests[$uriPart] = (int) ($doc['value'] ?? 0);
                }
            }
        } catch (\Exception $e) {
            // Return empty if MongoDB query fails
        }

        arsort($requests);

        return response()->json(
            \array_slice($requests, $offset, 1000)
        );
    }
}
