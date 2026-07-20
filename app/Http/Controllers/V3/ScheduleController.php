<?php

namespace App\Http\Controllers\V3;

use Jikan\Request\Schedule\ScheduleRequest;

class ScheduleController extends Controller
{
    private const VALID_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday',
        'other',
        'unknown',
    ];

    public function main(?string $day = null)
    {
        if (null !== $day && !\in_array(strtolower($day), self::VALID_DAYS, true)) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => "Invalid day: {$day}. Must be one of: " . implode(', ', self::VALID_DAYS)
            ])->setStatusCode(400);
        }

        try {
            $schedule = $this->jikan->getSchedule(new ScheduleRequest());

            if (null !== $day) {
                $schedule = [
                    strtolower($day) => $schedule->{'get'.ucfirst(strtolower($day))}(),
                ];
            }

            return response($this->serializer->serialize($schedule, 'json'));
            
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return response()->json([
                'error' => 'Connection Timeout',
                'message' => 'MAL server took too long to respond when fetching schedule data.',
                'retry_after' => 60,
                'suggestion' => 'Try again in 60 seconds. Schedule data is also available via /v4/season endpoint.'
            ])->setStatusCode(504);
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            if (stripos($errorMessage, 'timeout') !== false || stripos($errorMessage, 'timed out') !== false) {
                return response()->json([
                    'error' => 'Request Timeout',
                    'message' => 'Schedule request timed out. MAL schedule pages can be slow to load.',
                    'retry_after' => 30,
                    'alternative_endpoints' => [
                        '/v4/season' => 'Get currently airing anime (includes broadcast day info)',
                        '/v4/anime?status=airing&page=1' => 'Get all currently airing anime'
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
