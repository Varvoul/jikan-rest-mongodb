<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class Throttle
{
    public $maxAttemptsPerDecayMinutes = 30;
    public $maxAttemptsPerConcurrency = 2;
    public $decayMinutes = 1;

    private $userRequests = [];

    public function handle(Request $request, Closure $next)
    {
        if (!env('THROTTLE', false)) {
            return $next($request);
        }

        // don't throttle base requests
        if ($request->is('/')) {
            return $next($request);
        }

        $this->decayMinutes = (int) env('THROTTLE_DECAY_MINUTES', 1);
        $this->maxAttemptsPerDecayMinutes = (int) env('THROTTLE_MAX_REQUESTS_PER_DECAY_MINUTES', 60);
        $this->maxAttemptsPerConcurrency = (int) env('THROTTLE_MAX_REQUESTS_PER_SECOND', 2);

        $signature = $this->resolveRequestSignature($request);
        $key = "throttle:user:{$signature}:" . time();

        $this->hit($key);

        // Get all keys for this user
        $allKeys = [];
        $now = time();
        for ($i = 0; $i < $this->decayMinutes * 60; $i++) {
            $k = "throttle:user:{$signature}:" . ($now - $i);
            $val = Cache::get($k);
            if ($val !== null) {
                $allKeys[$k] = (int) $val;
            }
        }

        // throttle requests per decay minutes
        if (array_sum($allKeys) > $this->maxAttemptsPerDecayMinutes) {
            return response()->json([
                'error' => 'You are being rate limited [MAX: '.$this->maxAttemptsPerDecayMinutes.' requests/'.$this->decayMinutes.' minute(s)]'
            ], 429);
        }

        // throttle concurrent requests
        $requestsThisSecond = (int) Cache::get($key);
        if ($requestsThisSecond > $this->maxAttemptsPerConcurrency) {
            return response()->json([
                'error' => 'You are being rate limited [MAX: '.$this->maxAttemptsPerConcurrency.' requests/second]'
            ], 429);
        }

        return $next($request);
    }

    protected function resolveRequestSignature(Request $request)
    {
        return sha1(
            $request->getHost() . '|' . $request->ip()
        );
    }

    protected function hit(string $key)
    {
        if (!Cache::has($key)) {
            Cache::put($key, 0, $this->decayMinutes * 60);
        }
        Cache::increment($key);
    }
}