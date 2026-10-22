<?php

namespace App\Http;

use Illuminate\Http\Request;

class HttpHelper
{
    public static function resolveRequestFingerprint(Request $request): string
    {
        return sprintf("request:%s:%s", self::requestType($request), self::getRequestUriHash($request));
    }

    public static function hasError($response): bool
    {
        // Any HTTP-level error status is authoritative (controllers always set
        // 4xx/5xx on failures — catching this first avoids body decoding).
        if (\is_object($response) && \method_exists($response, 'getStatusCode')
            && (int) $response->getStatusCode() >= 400
        ) {
            return true;
        }

        $original = $response->original ?? null;

        if (\is_array($original)) {
            return self::isErrorPayload($original);
        }

        return false;
    }

    /**
     * Detect error payloads stored in the cache (no status code available there).
     *
     * Covers BOTH known error shapes:
     *   1. V4 ListController::errorResponse  {status: 500, type: 'Exception', message, error: null}
     *      — the old isset($data['error']) check missed this because `error` is null.
     *   2. V3 400 shape                      {error: 'Bad Request', message}
     *
     * Legit media payloads can carry `status` ("Currently Airing") and `type`
     * ("TV") but never an integer >= 400 status together with a `message` key.
     */
    public static function isErrorPayload(array $data): bool
    {
        if (\array_key_exists('error', $data) && $data['error'] !== null) {
            return true;
        }

        if (!\array_key_exists('status', $data)
            || !\array_key_exists('type', $data)
            || !\array_key_exists('message', $data)
        ) {
            return false;
        }

        return \is_numeric($data['status']) && (int) $data['status'] >= 400;
    }

    public static function requestType(Request $request): string
    {
        $requestType = $request->segments()[1];
        if (!\in_array($request->segments()[0], ['v1', 'v2', 'v3'])) {
            $requestType = $request->segments()[0];
        }

        return $requestType;
    }

    public static function requestCacheExpiry(string $requestType): int
    {
        $requestType = strtoupper($requestType);
        return (int) (env("CACHE_{$requestType}_EXPIRE") ?? env('CACHE_DEFAULT_EXPIRE'));
    }

    public static function requestAPIVersion(Request $request) : int
    {
        return (int) str_replace('v', '', $request->segment(1));
    }

    public static function serializeEmptyObjects(string $requestType, array $data)
    {
        if (!($requestType === 'anime' || $requestType === 'manga')) {
            return $data;
        }

        if (isset($data['related']) && \count($data['related']) === 0) {
            $data['related'] = new \stdClass();
        }

        if (isset($data['related'])) {
            $related = $data['related'];
            $data['related'] = [];

            foreach ($related as $relation => $items) {
                $data['related'][] = [
                    'relation' => $relation,
                    'items' => $items
                ];
            }
        }

        return $data;
    }

    public static function serializeEmptyObjectsControllerLevel(array $data)
    {
        if (isset($data['related']) && \count($data['related']) === 0) {
            $data['related'] = new \stdClass();
        }

        if (isset($data['related'])) {
            $related = $data['related'];
            $data['related'] = [];

            foreach ($related as $relation => $items) {
                $data['related'][] = [
                    'relation' => $relation,
                    'items' => $items
                ];
            }
        }

        return $data;
    }

    public static function requestControllerName(Request $request) : string
    {
        $route = explode('\\', $request->route()[1]['uses']);
        $route = end($route);

        return explode('@', $route)[0];
    }

    public static function getRequestUriHash(Request $request) : string
    {
        return sha1($request->getRequestUri());
    }

}
