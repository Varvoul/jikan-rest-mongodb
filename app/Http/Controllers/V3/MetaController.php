<?php

namespace App\Http\Controllers\V3;

use Illuminate\Support\Facades\Cache;
use MongoDB\Client;

class MetaController extends Controller
{
    public function debug()
    {
        $info = [];
        $info['patch_file_exists'] = file_exists('/app/patch-related.php');
        // Check ALL installed packages for jikan
        $installed = '/app/vendor/composer/installed.json';
        if (file_exists($installed)) {
            $raw = file_get_contents($installed);
            $data = json_decode($raw, true);
            foreach ($data as $p) {
                $name = $p['name'] ?? '';
                if (strpos($name, 'jikan') !== false || strpos($name, 'mongodb') !== false) {
                    $info['packages'][$name] = $p['version'] ?? '?';
                }
            }
        }
        // Check if AnimeParser was patched
        $parserFile = '/app/vendor/jikan-me/jikan/src/Parser/Anime/AnimeParser.php';
        if (file_exists($parserFile)) {
            $content = file_get_contents($parserFile);
            $info['parser_file_exists'] = true;
            $info['parser_file_size'] = strlen($content);
            $info['has_old_parser'] = strpos($content, 'anime_detail_related_anime') !== false;
            $info['has_new_tile_parser'] = strpos($content, 'entries-tile') !== false;
            // Show full getRelated method
            $pos = strpos($content, 'function getRelated');
            if ($pos !== false) {
                $info['getRelated_start'] = substr($content, $pos, 600);
            }
        } else {
            $info['parser_file_exists'] = false;
        }
        // Check entrypoint log for patch-related
        $logFile = '/tmp/patch-related.log';
        if (file_exists($logFile)) {
            $info['patch_log'] = file_get_contents($logFile);
        } else {
            $info['patch_log'] = 'FILE NOT FOUND - patch may not have been attempted';
        }
        // Also show what's around getRelated in the source
        $pos = strpos($content, 'function getRelated');
        if ($pos !== false) {
            // Show 20 chars before to see if 'public' is there
            $info['getRelated_context_before'] = substr($content, max(0, $pos-30), 30);
            $info['getRelated_start'] = substr($content, $pos, 600);
        }
        return response()->json($info);
    }

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
