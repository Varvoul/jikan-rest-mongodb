<?php

namespace App\Http\Controllers\V3;

use Illuminate\Http\Request;
use Jikan\Request\Schedule\ScheduleRequest;
use Jikan\Request\Anime\AnimeRequest;

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

    /**
     * Default items per page for schedule endpoints
     */
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function main(Request $request, ?string $day = null)
    {
        if (null !== $day && !\in_array(strtolower($day), self::VALID_DAYS, true)) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => "Invalid day: {$day}. Must be one of: " . implode(', ', self::VALID_DAYS)
            ])->setStatusCode(400);
        }

        // Parse pagination parameters
        $page = max(1, (int) $request->get('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->get('limit', self::DEFAULT_LIMIT)));

        try {
            $scheduleData = $this->jikan->getSchedule(new ScheduleRequest());
            
            if (null !== $day) {
                // Single day requested - get only that day's anime
                $dayLower = strtolower($day);
                $getter = 'get' . ucfirst($dayLower);
                
                // Handle special case for "other" and "unknown"
                if ($dayLower === 'other') {
                    $getter = 'getOther';
                } elseif ($dayLower === 'unknown') {
                    $getter = 'getUnknown';
                }
                
                $daySchedule = method_exists($scheduleData, $getter) 
                    ? $scheduleData->$getter() 
                    : [];
                
                // Enrich anime entries with full data
                $enrichedAnime = [];
                foreach ($daySchedule as $anime) {
                    // Convert AnimeCard object to array if needed
                    $animeArray = is_array($anime) ? $anime : json_decode($this->serializer->serialize($anime, 'json'), true);
                    $enrichedAnime[] = $this->enrichScheduleAnime($animeArray);
                }
                
                // Paginate results
                $totalAnime = count($enrichedAnime);
                $lastPage = max(1, (int) ceil($totalAnime / $limit));
                $offset = ($page - 1) * $limit;
                $paginatedAnime = array_slice($enrichedAnime, $offset, $limit);
                
                return response(json_encode([
                    'request_hash' => ('request:v4:schedule:' . $day . ':' . md5(time())),
                    'request_cached' => false,
                    'request_cache_expiry' => 86400,
                    'day' => $dayLower,
                    'pagination' => [
                        'last_visible_page' => $lastPage,
                        'has_next_page' => $page < $lastPage,
                        'current_page' => $page,
                        'items' => [
                            'count' => count($paginatedAnime),
                            'total' => $totalAnime,
                            'per_page' => $limit,
                        ],
                    ],
                    'data' => array_values($paginatedAnime),
                ]));
            }
            
            // All days requested - return structured by day with pagination info per day
            $allDays = [];
            $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday', 'other', 'unknown'];
            
            foreach ($validDays as $dayName) {
                $getter = 'get' . ucfirst($dayName);
                if ($dayName === 'other') $getter = 'getOther';
                if ($dayName === 'unknown') $getter = 'getUnknown';
                
                $dayAnime = method_exists($scheduleData, $getter) 
                    ? $scheduleData->$getter() 
                    : [];
                
                // Enrich each anime entry
                $enrichedDayAnime = [];
                foreach ($dayAnime as $anime) {
                    // Convert AnimeCard object to array if needed
                    $animeArray = is_array($anime) ? $anime : json_decode($this->serializer->serialize($anime, 'json'), true);
                    $enrichedDayAnime[] = $this->enrichScheduleAnime($animeArray);
                }
                
                $allDays[$dayName] = [
                    'count' => count($enrichedDayAnime),
                    'pagination' => [
                        'last_visible_page' => max(1, (int) ceil(count($enrichedDayAnime) / $limit)),
                        'has_next_page' => count($enrichedDayAnime) > $limit,
                        'items' => [
                            'total' => count($enrichedDayAnime),
                            'per_page' => $limit,
                        ],
                    ],
                    // Include first page of data inline
                    'data' => array_slice($enrichedDayAnime, 0, $limit),
                ];
            }
            
            return response(json_encode([
                'request_hash' => ('request:v4:schedule:all:' . md5(time())),
                'request_cached' => false,
                'request_cache_expiry' => 86400,
                'pagination' => [
                    'note' => 'Use /v4/schedule/{day} for paginated single-day results',
                    'available_days' => $validDays,
                ],
                'data' => $allDays,
            ]));
            
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
                        '/v4/anime?status=airing?page=1' => 'Get all currently airing anime'
                    ]
                ])->setStatusCode(504);
            }
            
            return response()->json([
                'error' => 'Internal Error',
                'message' => $errorMessage
            ])->setStatusCode(500);
        }
    }

    /**
     * Enrich a schedule anime entry with full details from MAL.
     */
    private function enrichScheduleAnime(array $scheduleEntry): array
    {
        $malId = $scheduleEntry['mal_id'] ?? null;
        
        if (!$malId) {
            return $this->convertScheduleToV4Format($scheduleEntry);
        }
        
        try {
            // Fetch full anime data
            $fullAnime = $this->jikan->getAnime(new AnimeRequest((int)$malId));
            $fullData = json_decode($this->serializer->serialize($fullAnime, 'json'), true);
            
            return [
                'mal_id' => $fullData['mal_id'] ?? $malId,
                'url' => $fullData['url'] ?? $scheduleEntry['url'] ?? "https://myanimelist.net/anime/{$malId}",
                'images' => $fullData['images'] ?? [
                    'jpg' => [
                        'image_url' => $scheduleEntry['image_url'] ?? null,
                        'small_image_url' => null,
                        'large_image_url' => null,
                    ],
                    'webp' => [
                        'image_url' => str_replace('.jpg', '.webp', $scheduleEntry['image_url'] ?? ''),
                        'small_image_url' => null,
                        'large_image_url' => null,
                    ],
                ],
                'trailer' => $fullData['trailer'] ?? [
                    'youtube_id' => null,
                    'url' => null,
                    'embed_url' => null,
                    'images' => [
                        'image_url' => null,
                        'small_image_url' => null,
                        'medium_image_url' => null,
                        'large_image_url' => null,
                        'maximum_image_url' => null,
                    ],
                ],
                'approved' => $fullData['approved'] ?? true,
                'titles' => $fullData['titles'] ?? [
                    ['type' => 'Default', 'title' => $scheduleEntry['title'] ?? 'Unknown'],
                ],
                'title' => $fullData['title'] ?? $scheduleEntry['title'] ?? 'Unknown',
                'title_english' => $fullData['title_english'] ?? null,
                'title_japanese' => $fullData['title_japanese'] ?? null,
                'title_synonyms' => $fullData['title_synonyms'] ?? [],
                'type' => $fullData['type'] ?? null,
                'source' => $fullData['source'] ?? null,
                'episodes' => $fullData['episodes'] ?? null,
                'status' => $fullData['status'] ?? 'Currently Airing',
                'airing' => $fullData['airing'] ?? true,
                'aired' => $fullData['aired'] ?? [
                    'from' => null,
                    'to' => null,
                    'prop' => ['from' => null, 'to' => null],
                    'string' => '',
                ],
                'duration' => $fullData['duration'] ?? null,
                'rating' => $fullData['rating'] ?? null,
                'score' => $fullData['score'] ?? $scheduleEntry['score'] ?? null,
                'scored_by' => $fullData['scored_by'] ?? $scheduleEntry['members'] ?? null,
                'rank' => $fullData['rank'] ?? null,
                'popularity' => $fullData['popularity'] ?? null,
                'members' => $fullData['members'] ?? $scheduleEntry['members'] ?? null,
                'favorites' => $fullData['favorites'] ?? 0,
                'synopsis' => $fullData['synopsis'] ?? null,
                'background' => $fullData['background'] ?? null,
                'premiered' => $fullData['premiered'] ?? null,
                'broadcast' => $fullData['broadcast'] ?? [
                    'string' => $scheduleEntry['broadcast']['string'] ?? ($scheduleEntry['broadcast'] ?? null),
                    'day' => strtolower($scheduleEntry['broadcast']['day'] ?? ''),
                    'time' => $scheduleEntry['broadcast']['time'] ?? null,
                    'timezone' => $scheduleEntry['broadcast']['timezone'] ?? 'Asia/Tokyo',
                ],
                'producers' => $fullData['producers'] ?? [],
                'licensors' => $fullData['licensors'] ?? [],
                'studios' => $fullData['studios'] ?? [],
                'genres' => $fullData['genres'] ?? $scheduleEntry['genres'] ?? [],
                'explicit_genres' => $fullData['explicit_genres'] ?? [],
                'themes' => $fullData['themes'] ?? [],
                'demographics' => $fullData['demographics'] ?? [],
                'r18' => false,
                'kids' => $scheduleEntry['kids'] ?? false,
                'broadcast_time' => $scheduleEntry['broadcast']['string'] ?? $scheduleEntry['broadcast'] ?? null,
            ];
            
        } catch (\Exception $e) {
            return $this->convertScheduleToV4Format($scheduleEntry);
        }
    }

    /**
     * Convert schedule entry to V4 format without additional API calls.
     */
    private function convertScheduleToV4Format(array $entry): array
    {
        $broadcastStr = $entry['broadcast']['string'] ?? $entry['broadcast'] ?? null;
        
        return [
            'mal_id' => $entry['mal_id'] ?? null,
            'url' => $entry['url'] ?? null,
            'images' => [
                'jpg' => [
                    'image_url' => $entry['image_url'] ?? null,
                    'small_image_url' => null,
                    'large_image_url' => null,
                ],
                'webp' => [
                    'image_url' => str_replace('.jpg', '.webp', $entry['image_url'] ?? ''),
                    'small_image_url' => null,
                    'large_image_url' => null,
                ],
            ],
            'trailer' => [
                'youtube_id' => null,
                'url' => null,
                'embed_url' => null,
                'images' => [
                    'image_url' => null,
                    'small_image_url' => null,
                    'medium_image_url' => null,
                    'large_image_url' => null,
                    'maximum_image_url' => null,
                ],
            ],
            'approved' => true,
            'titles' => [
                ['type' => 'Default', 'title' => $entry['title'] ?? 'Unknown'],
            ],
            'title' => $entry['title'] ?? 'Unknown',
            'title_english' => null,
            'title_japanese' => null,
            'title_synonyms' => [],
            'type' => $entry['type'] ?? null,
            'source' => null,
            'episodes' => $entry['episodes'] ?? null,
            'status' => 'Currently Airing',
            'airing' => true,
            'aired' => [
                'from' => null,
                'to' => null,
                'prop' => ['from' => null, 'to' => null],
                'string' => '',
            ],
            'duration' => null,
            'rating' => null,
            'score' => $entry['score'] ?? null,
            'scored_by' => $entry['members'] ?? null,
            'rank' => null,
            'popularity' => null,
            'members' => $entry['members'] ?? null,
            'favorites' => 0,
            'synopsis' => null,
            'background' => null,
            'premiered' => null,
            'broadcast' => [
                'string' => $broadcastStr,
                'day' => '',
                'time' => null,
                'timezone' => 'Asia/Tokyo',
            ],
            'producers' => [],
            'licensors' => [],
            'studios' => [],
            'genres' => $entry['genres'] ?? [],
            'explicit_genres' => [],
            'themes' => [],
            'demographics' => [],
            'r18' => false,
            'kids' => $entry['kids'] ?? false,
            'broadcast_time' => $broadcastStr,
        ];
    }
}
