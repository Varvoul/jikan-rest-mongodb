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
