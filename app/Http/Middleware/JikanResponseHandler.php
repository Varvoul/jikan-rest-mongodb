<?php

/**
 * This middleware is the successor of JikanResponseLegacy; used for REST v3.3+
 *
 * MongoDB-based caching version for self-hosted deployment
 *
 * If a request is past it's TTL, it serves stale cache
 * For self-hosted, queue-based updates are disabled (CACHE_METHOD=legacy)
 */

namespace App\Http\Middleware;

use App\Http\HttpHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class JikanResponseHandler
{
    private $requestUriHash;
    private $requestUriHash;
    private $requestType;
    private $requestCacheExpiry = 0;
    private $requestCached = false;
    private $requestCacheTtl;

    private $fingerprint;
    private $cacheExpiryFingerprint;

    private $route;

    private $queueable = true;

    private const NON_QUEUEABLE = [
        'UserController@profile',
        'UserController@history',
        'UserController@friends',
        'UserController@animelist',
        'UserController@mangalist',
        // /full endpoint is also non-queueable as it combines multiple requests
        'AnimeController@full',
        'MangaController@full',
    ];

    private const HIGH_PRIORITY_QUEUE = [
        'ScheduleController@main'
    ];

    public function handle(Request $request, Closure $next)
    {
        if ($request->header('auth') === env('APP_KEY')) {
            return $next($request);
        }

        if (empty($request->segments())) {
            return $next($request);
        }

        if (!isset($request->segments()[1])) {
            return $next($request);
        }

        if (\in_array('meta', $request->segments())) {
            return $next($request);
        }

        $this->requestUriHash = HttpHelper::getRequestUriHash($request);
        $this->requestType = HttpHelper::requestType($request);
        $this->requestCacheTtl = HttpHelper::requestCacheExpiry($this->requestType);
        $this->fingerprint = HttpHelper::resolveRequestFingerprint($request);
        $this->cacheExpiryFingerprint = "ttl:{$this->fingerprint}";
        $this->requestCached = Cache::has($this->fingerprint);

        $this->route = explode('\\', $request->route()[1]['uses']);
        $this->route = end($this->route);

        // Check if request is in the 404 cache pool
        if (Cache::has("request:404:{$this->requestUriHash}")) {
            return response()
                ->json([
                    'status' => 404,
                    'type' => 'BadResponseException',
                    'message' => 'Resource does not exist',
                    'error' => Cache::get("request:404:{$this->requestUriHash}")
                ], 404);
        }

        // Is the request queueable? (disabled for self-hosted, always use legacy mode)
        if (\in_array($this->route, self::NON_QUEUEABLE) || env('CACHE_METHOD', 'legacy') === 'legacy') {
            $this->queueable = false;
        }

        // Cache if it doesn't exist
        if (!$this->requestCached) {
            $response = $next($request);

            if (HttpHelper::hasError($response)) {
                return $response;
            }

            Cache::forever($this->fingerprint, $response->original);
            Cache::forever($this->cacheExpiryFingerprint, time() + $this->requestCacheTtl);
        }

        // If cache is expired and not queueable, re-fetch
        $this->requestCacheExpiry = (int) Cache::get($this->cacheExpiryFingerprint);

        if ($this->requestCached && $this->requestCacheExpiry <= time() && !$this->queueable) {
            $response = $next($request);

            if (HttpHelper::hasError($response)) {
                return $response;
            }

            Cache::forever($this->fingerprint, $response->original);
            Cache::forever($this->cacheExpiryFingerprint, time() + $this->requestCacheTtl);
            $this->requestCacheExpiry = (int) Cache::get($this->cacheExpiryFingerprint);
        }

        // Queue-based updates disabled for self-hosted (no Redis)
        // In legacy mode, we just re-fetch on expiry (handled above)

        // Return response
        $meta = $this->generateMeta($request);

        $cache = Cache::get($this->fingerprint);
        $cacheMutable = json_decode($cache, true);
        $cacheMutable = $this->cacheMutation($cacheMutable);

        $response = array_merge($meta, $cacheMutable);

        $headers = [
            'X-Request-Hash' => $this->fingerprint,
            'X-Request-Cached' => $this->requestCached,
            'X-Request-Cache-Ttl' => (int) $this->requestCacheExpiry - time()
        ];

        if (env('APP_DEPRECATION')) {
            $headers['X-API-Deprecation'] = env('APP_DEPRECATION');
            $headers['X-API-Deprecation-Date'] = env('APP_DEPRECATION_DATE');
            $headers['X-API-Deprecation-Info'] = env('APP_DEPRECATION_INFO');
        }

        // Build and return response
        return response()
            ->json(
                $response
            )
            ->setEtag(
                md5($cache)
            )
            ->withHeaders($headers)
            ->setExpires((new \DateTime())->setTimestamp($this->requestCacheExpiry));
    }

    private function generateMeta(Request $request) : array
    {
        $version = HttpHelper::requestAPIVersion($request);

        $meta = [
            'request_hash' => $this->fingerprint,
            'request_cached' => $this->requestCached,
            'request_cache_expiry' => (int) $this->requestCacheExpiry - time()
        ];

        if (env('APP_DEPRECATION')) {
            $meta['API_DEPRECATION'] = env('APP_DEPRECATION');
            $meta['API_DEPRECATION_DATE'] = env('APP_DEPRECATION_DATE');
            $meta['API_DEPRECATION_INFO'] = env('APP_DEPRECATION_INFO');
        }

        return $meta;
    }

    private function cacheMutation(array $data) : array
    {
        if (!($this->requestType === 'anime' || $this->requestType === 'manga')) {
            return $data;
        }

        // Fix JSON response for empty related object
        if (isset($data['related']) && \count($data['related']) === 0) {
            $data['related'] = new \stdClass();
        }

        return $data;
    }
}