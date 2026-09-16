<?php

namespace App\Http\Middleware;

use App\Http\HttpHelper;
use Closure;
use Illuminate\Support\Facades\Cache;

class EtagMiddleware
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

        if (empty($request->segments())) {
            return $next($request);
        }

        if (!isset($request->segments()[1])) {
            return $next($request);
        }

        if (\in_array('meta', $request->segments())) {
            return $next($request);
        }

        $fingerprint = HttpHelper::resolveRequestFingerprint($request);

        // 304 revalidation must compare against the ETag of the FINAL decorated
        // body (post cacheMutation / title_romaji), matching what
        // JikanResponseHandler actually serves. Comparing the raw pre-mutation
        // cache hash made clients holding pre-patch ETags receive 304 forever
        // and never see schema-affecting patches.
        $cached = Cache::get($fingerprint);
        if (
            $request->hasHeader('If-None-Match')
            && \is_string($cached)
            && $cached !== ''
        ) {
            $decoded = json_decode($cached, true);
            if (\is_array($decoded)) {
                $etag = md5(
                    json_encode(
                        JikanResponseHandler::decorateForResponse(
                            $decoded,
                            HttpHelper::requestType($request)
                        )
                    )
                );

                // Normalize the incoming header: browsers echo the stored ETag
                // verbatim, e.g. W/"abc" or "abc" -> abc
                $incoming = \trim((string) $request->header('If-None-Match'));
                $incoming = \preg_replace('/^W\//', '', $incoming);
                $incoming = \trim((string) $incoming, '"');

                if ($etag === $incoming) {
                    return response('', 304)
                        ->header('ETag', $request->header('If-None-Match'))
                        ->header('Cache-Control', 'private, no-cache, max-age=0, must-revalidate');
                }
            }
        }

        return $next($request);
    }
}
