<?php

namespace App\Http\Middleware;

use App\Http\HttpHelper;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MicroCaching
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        if ($request->header('auth') === env('APP_KEY')) {
            return $next($request);
        }

        // Microcaching disabled for MongoDB-based self-hosted deployment
        if (!env('MICROCACHING', false)) {
            return $next($request);
        }

        $fingerprint = "microcache:".HttpHelper::resolveRequestFingerprint($request);
        $cached = Cache::get($fingerprint);
        
        if ($cached !== null) {
            return response()
                ->json(
                    json_decode($cached, true)
                );
        }

        return $next($request);
    }

    public static function setMicroCache($fingerprint, $cache) {
        $fingerprint = "microcache:".$fingerprint;
        $cache = json_encode($cache);

        Cache::put($fingerprint, $cache, env('MICROCACHING_EXPIRE', 5));
    }
}