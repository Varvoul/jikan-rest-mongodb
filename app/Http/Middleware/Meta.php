<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Support\Facades\Cache;

class Meta
{
    private $request;

    public function handle($request, Closure $next)
    {
        // pass on meta requests
        if (\in_array('meta', $request->segments())) {
            return $next($request);
        }

        $requestUri = $request->getRequestUri();
        $requestUri = str_replace(['/v1', '/v2', '/v3'], '', $requestUri);

        $response = $next($request);
        if (isset($response->original['error'])) {
            return $response;
        }

        $this->updateMeta("requests:today", $requestUri, 86400);
        $this->updateMeta("requests:weekly", $requestUri, 604800);
        $this->updateMeta("requests:monthly", $requestUri, 2629746);

        return $response;
    }

    private function updateMeta($key, $req, $expire)
    {
        $hashKey = $key . ":" . $req;
        $current = Cache::get($hashKey);
        if ($current === null) {
            Cache::put($hashKey, 1, $expire);
        } else {
            Cache::put($hashKey, (int) $current + 1, $expire);
        }
    }
}
