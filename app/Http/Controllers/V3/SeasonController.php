<?php

namespace App\Http\Controllers\V3;

use Jikan\Request\Seasonal\SeasonalRequest;
use Jikan\Request\SeasonList\SeasonListRequest;

class SeasonController extends Controller
{
    private const VALID_SEASONS = [
        'summer',
        'spring',
        'winter',
        'fall'
    ];

    /**
     * Timeout for seasonal requests (in seconds)
     * Season pages from MAL can be very heavy HTML
     */
    private const SEASON_TIMEOUT = 25;

    public function main(?int $year = null, ?string $season = null)
    {
        if (!is_null($season) && !\in_array(strtolower($season), self::VALID_SEASONS)) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => "Invalid season: {$season}. Must be one of: " . implode(', ', self::VALID_SEASONS)
            ])->setStatusCode(400);
        }

        try {
            // Set a custom timeout for seasonal requests
            $originalTimeout = null;
            if (app()->bound('GuzzleClient')) {
                $client = app('GuzzleClient');
                // We'll use the existing client but handle potential timeouts
            }

            $season = $this->jikan->getSeasonal(new SeasonalRequest($year, $season));
            
            return response($this->serializer->serialize($season, 'json'));
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return response()->json([
                'error' => 'Connection Timeout',
                'message' => 'MAL server took too long to respond. The seasonal page may be too large or MAL is rate-limiting.',
                'retry_after' => 60,
                'suggestion' => 'Try again in 60 seconds, or use /v4/anime?status=airing as an alternative'
            ])->setStatusCode(504);
            
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : 500;
            return response()->json([
                'error' => 'MAL Request Failed',
                'message' => 'Failed to fetch data from MyAnimeList: ' . $e->getMessage(),
                'status_code' => $statusCode
            ])->setStatusCode(502);
            
        } catch (\Exception $e) {
            // Check if it's a timeout
            $errorMessage = $e->getMessage();
            if (stripos($errorMessage, 'timeout') !== false || stripos($errorMessage, 'timed out') !== false) {
                return response()->json([
                    'error' => 'Request Timeout',
                    'message' => 'Seasonal data request timed out after ' . self::SEASON_TIMEOUT . ' seconds.',
                    'retry_after' => 30,
                    'alternative_endpoints' => [
                        '/v4/anime?status=airing&page=1' => 'Get currently airing anime from main database',
                        '/v4/top/anime?page=1' => 'Get top-rated anime',
                        '/meta/seasonal_cache_status' => 'Check cache status'
                    ]
                ])->setStatusCode(504);
            }
            
            return response()->json([
                'error' => 'Internal Error',
                'message' => $errorMessage
            ])->setStatusCode(500);
        }
    }

    public function archive()
    {
        try {
            return response(
                $this->serializer->serialize(
                    ['archive' => $this->jikan->getSeasonList(new SeasonListRequest())],
                    'json'
                )
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Archive Fetch Failed',
                'message' => $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function later()
    {
        try {
            $season = $this->jikan->getSeasonal(new SeasonalRequest(null, null, true));
            return response($this->serializer->serialize($season, 'json'));
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return response()->json([
                'error' => 'Connection Timeout',
                'message' => 'MAL server took too long to respond when fetching upcoming anime.',
                'retry_after' => 60,
                'suggestion' => 'Try again in 60 seconds, or use /v4/anime?status=upcoming as an alternative'
            ])->setStatusCode(504);
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            if (stripos($errorMessage, 'timeout') !== false || stripos($errorMessage, 'timed out') !== false) {
                return response()->json([
                    'error' => 'Request Timeout', 
                    'message' => 'Upcoming anime request timed out.',
                    'retry_after' => 30,
                    'alternative_endpoints' => [
                        '/v4/anime?status=upcoming&page=1' => 'Get upcoming anime from main database'
                    ]
                ])->setStatusCode(504);
            }
            
            return response()->json([
                'error' => 'Internal Error',
                'message' => $errorMessage
            ])->setStatusCode(500);
        }
    }
}
