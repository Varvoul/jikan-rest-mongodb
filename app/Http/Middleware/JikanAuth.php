<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Jikan Auth Gate — same pattern as CF workers and Vercel proxies.
 * 
 * Checks:
 *   1. Doc endpoint at / → serves HTML doc (no auth, no secrets)
 *   2. X-Proxy-Key header must match env('JIKAN_HEADER_SECRET')
 *   3. URL path must start with /p/<JIKAN_URL_PATH_SECRET>/
 *   4. Strips the /p/<secret>/ prefix before passing to the router
 *   5. Blocks bot UAs when auth is missing
 * 
 * Env vars (set as Render secrets):
 *   JIKAN_HEADER_SECRET    — 9-char alphanumeric
 *   JIKAN_URL_PATH_SECRET  — 43-char URL-safe string
 */
class JikanAuth
{
    private const BOT_UA_RE = '/\b(curl|libcurl|python-requests|urllib|wget|scrapy|Go-http-client|httpx|PostmanRuntime|okhttp|java\/|Apache-HttpClient|node-fetch|axios\/|aiohttp|HTTPie|Wget|bot|crawler|spider|headless)\b/i';
    private const RL_LIMIT = 180;
    private const RL_WINDOW = 60; // seconds

    private static $rlMap = [];

    private function corsHeaders(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type, X-Proxy-Key',
            'Access-Control-Max-Age' => '86400',
        ];
    }

    private function unauthorized($message = 'Unauthorized')
    {
        $headers = array_merge(['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'], $this->corsHeaders());
        return response()->json(['error' => $message], 401, $headers);
    }

    private function rateLimited($retryAfter)
    {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store',
            'Retry-After' => $retryAfter,
        ], $this->corsHeaders());
        return response()->json(['error' => 'Rate limit exceeded', 'retryAfter' => $retryAfter], 429, $headers);
    }

    private function docResponse()
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"><title>Jikan API</title>'
              . '<style>body{font:14px/1.55 sans-serif;max-width:600px;margin:40px auto;padding:0 20px;color:#e6edf3;background:#0d1117}'
              . 'h1{font-size:22px}.tag{display:inline-block;font-size:11px;background:#e8e8ec;padding:2px 7px;border-radius:3px;color:#444}</style>'
              . '</head><body><h1>Jikan API <span class="tag">authenticated</span></h1>'
              . '<p>Self-hosted Jikan REST API (v4) with MongoDB caching.</p>'
              . '<p>This is an authenticated API endpoint. Contact the administrator for access credentials and usage documentation.</p>'
              . '</body></html>';
        return response($html, 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'public, max-age=300']);
    }

    public function handle(Request $request, Closure $next)
    {
        // CORS preflight
        if ($request->isMethod('OPTIONS')) {
            return response('', 204, $this->corsHeaders());
        }

        $path = $request->getPathInfo();

        // Doc endpoint at / — no auth, no secrets
        if ($path === '/' || $path === '') {
            return $this->docResponse();
        }

        $headerSecret = env('JIKAN_HEADER_SECRET');
        $urlSecret = env('JIKAN_URL_PATH_SECRET');

        if (!$headerSecret || !$urlSecret) {
            return response()->json(['error' => 'Server misconfigured'], 500, $this->corsHeaders());
        }

        // Check X-Proxy-Key header
        $providedKey = $request->header('X-Proxy-Key');
        $keyOk = $providedKey === $headerSecret;

        if (!$keyOk) {
            $ua = $request->header('User-Agent', '');
            if (preg_match(self::BOT_UA_RE, $ua)) {
                return $this->unauthorized('Blocked: automated traffic');
            }
            return $this->unauthorized();
        }

        // Rate limit
        $ip = $request->header('CF-Connecting-IP', $request->ip() ?: 'unknown');
        $now = time();
        if (!isset(self::$rlMap[$ip]) || self::$rlMap[$ip]['reset'] < $now) {
            self::$rlMap[$ip] = ['count' => 0, 'reset' => $now + self::RL_WINDOW];
        }
        self::$rlMap[$ip]['count']++;
        if (self::$rlMap[$ip]['count'] > self::RL_LIMIT) {
            $retryAfter = max(1, self::$rlMap[$ip]['reset'] - $now);
            return $this->rateLimited($retryAfter);
        }

        // Check URL path prefix
        $prefix = '/p/' . $urlSecret . '/';
        $barePrefix = '/p/' . $urlSecret;

        if ($path === $barePrefix) {
            // Strip to root
            $request->server->set('REQUEST_URI', '/');
            $request->server->set('PATH_INFO', '/');
        } else if (strpos($path, $prefix) === 0) {
            // Strip the /p/<secret>/ prefix
            $rest = substr($path, strlen($prefix) - 1); // keep the leading /
            // Preserve query string
            $queryString = $request->server->get('QUERY_STRING', '');
            $newUri = $rest . ($queryString ? '?' . $queryString : '');
            $request->server->set('REQUEST_URI', $newUri);
            $request->server->set('PATH_INFO', $rest);
        } else {
            return $this->unauthorized();
        }

        return $next($request);
    }
}
