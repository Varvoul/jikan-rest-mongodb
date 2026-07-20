<?php

namespace App\Http\Controllers\V3;

use Jikan\Request\Seasonal\SeasonalRequest;
use Jikan\Request\SeasonList\SeasonListRequest;
use Jikan\Request\Anime\AnimeRequest;

class SeasonController extends Controller
{
    private const VALID_SEASONS = [
        'summer',
        'spring',
        'winter',
        'fall'
    ];

    /**
     * Default items per page for seasonal endpoints
     */
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 100;

    public function main(?int $year = null, ?string $season = null)
    {
        if (!is_null($season) && !\in_array(strtolower($season), self::VALID_SEASONS)) {
            return response()->json([
                'error' => 'Bad Request',
                'message' => "Invalid season: {$season}. Must be one of: " . implode(', ', self::VALID_SEASONS)
            ])->setStatusCode(400);
        }

        // Parse pagination parameters
        $page = max(1, (int) request()->get('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) request()->get('limit', self::DEFAULT_LIMIT)));

        try {
            $seasonData = $this->jikan->getSeasonal(new SeasonalRequest($year, $season));
            $seasonJson = json_decode($this->serializer->serialize($seasonData, 'json'), true);
            
            // Extract anime list from seasonal data
            $animeList = $seasonJson['anime'] ?? [];
            
            // Enrich each anime entry with full details (to get episodes, type, etc.)
            $enrichedAnime = [];
            foreach ($animeList as $anime) {
                $enrichedAnime[] = $this->enrichSeasonalAnime($anime);
            }
            
            // Calculate pagination
            $totalAnime = count($enrichedAnime);
            $lastPage = max(1, (int) ceil($totalAnime / $limit));
            $offset = ($page - 1) * $limit;
            
            // Slice for current page
            $paginatedAnime = array_slice($enrichedAnime, $offset, $limit);
            
            // Build V4 paginated response
            return response(json_encode([
                'request_hash' => $seasonJson['request_hash'] ?? ('request:v4:season:' . md5(json_encode([$year, $season]))),
                'request_cached' => false,
                'request_cache_expiry' => 86400,
                'season_name' => $seasonJson['season_name'] ?? ucfirst($season ?? 'current'),
                'season_year' => $seasonJson['season_year'] ?? $year ?? date('Y'),
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
            $errorMessage = $e->getMessage();
            if (stripos($errorMessage, 'timeout') !== false || stripos($errorMessage, 'timed out') !== false) {
                return response()->json([
                    'error' => 'Request Timeout',
                    'message' => 'Seasonal data request timed out after 25 seconds.',
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
            $archiveData = $this->jikan->getSeasonList(new SeasonListRequest());
            $archiveJson = json_decode($this->serializer->serialize(['archive' => $archiveData], 'json'), true);
            
            // Add V4 wrapper
            return response(json_encode([
                'data' => $archiveJson['archive'] ?? [],
                'pagination' => [
                    'last_visible_page' => 1,
                    'has_next_page' => false,
                    'current_page' => 1,
                    'items' => [
                        'count' => count($archiveJson['archive'] ?? []),
                        'total' => count($archiveJson['archive'] ?? []),
                        'per_page' => 100,
                    ],
                ],
            ]));
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Archive Fetch Failed',
                'message' => $e->getMessage()
            ])->setStatusCode(500);
        }
    }

    public function later()
    {
        // Parse pagination parameters
        $page = max(1, (int) request()->get('page', 1));
        $limit = min(self::MAX_LIMIT, max(1, (int) request()->get('limit', self::DEFAULT_LIMIT)));

        try {
            $seasonData = $this->jikan->getSeasonal(new SeasonalRequest(null, null, true));
            $seasonJson = json_decode($this->serializer->serialize($seasonData, 'json'), true);
            
            // Extract anime list
            $animeList = $seasonJson['anime'] ?? [];
            
            // Enrich each anime entry with full details
            $enrichedAnime = [];
            foreach ($animeList as $anime) {
                $enrichedAnime[] = $this->enrichSeasonalAnime($anime);
            }
            
            // Calculate pagination
            $totalAnime = count($enrichedAnime);
            $lastPage = max(1, (int) ceil($totalAnime / $limit));
            $offset = ($page - 1) * $limit;
            
            // Slice for current page
            $paginatedAnime = array_slice($enrichedAnime, $offset, $limit);
            
            // Build V4 paginated response
            return response(json_encode([
                'request_hash' => $seasonJson['request_hash'] ?? ('request:v4:season:later:' . md5(time())),
                'request_cached' => false,
                'request_cache_expiry' => 86400,
                'season_name' => 'Later',
                'season_year' => null,
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
                        '/v4/anime?status=upcoming?page=1' => 'Get upcoming anime from main database'
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
     * Enrich a seasonal anime entry with full details from MAL.
     * This fixes the null episodes/type fields by fetching complete anime data.
     */
    private function enrichSeasonalAnime(array $seasonalEntry): array
    {
        $malId = $seasonalEntry['mal_id'] ?? null;
        
        if (!$malId) {
            return $this->convertToV4Format($seasonalEntry);
        }
        
        try {
            // Fetch full anime data to get accurate episodes, type, score, etc.
            $fullAnime = $this->jikan->getAnime(new AnimeRequest((int)$malId));
            $fullData = json_decode($this->serializer->serialize($fullAnime, 'json'), true);
            
            // Build comprehensive V4 entry from full data
            return [
                'mal_id' => $fullData['mal_id'] ?? $malId,
                'url' => $fullData['url'] ?? $seasonalEntry['url'] ?? "https://myanimelist.net/anime/{$malId}",
                'images' => $fullData['images'] ?? [
                    'jpg' => [
                        'image_url' => $seasonalEntry['image_url'] ?? null,
                        'small_image_url' => null,
                        'large_image_url' => null,
                    ],
                    'webp' => [
                        'image_url' => str_replace('.jpg', '.webp', $seasonalEntry['image_url'] ?? ''),
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
                    ['type' => 'Default', 'title' => $seasonalEntry['title'] ?? 'Unknown'],
                ],
                'title' => $fullData['title'] ?? $seasonalEntry['title'] ?? 'Unknown',
                'title_english' => $fullData['title_english'] ?? null,
                'title_japanese' => $fullData['title_japanese'] ?? null,
                'title_synonyms' => $fullData['title_synonyms'] ?? [],
                'type' => $fullData['type'] ?? $this->inferTypeFromAiringStart($seasonalEntry),
                'source' => $fullData['source'] ?? null,
                'episodes' => $fullData['episodes'] ?? $seasonalEntry['episodes'] ?? null,
                'status' => $fullData['status'] ?? $this->determineStatus($seasonalEntry),
                'airing' => $fullData['airing'] ?? isset($seasonalEntry['airing_start']),
                'aired' => $fullData['aired'] ?? [
                    'from' => $seasonalEntry['airing_start'] ?? null,
                    'to' => null,
                    'prop' => ['from' => null, 'to' => null],
                    'string' => $seasonalEntry['airing_start'] ?? '',
                ],
                'duration' => $fullData['duration'] ?? null,
                'rating' => $fullData['rating'] ?? null,
                'score' => $fullData['score'] ?? $seasonalEntry['score'] ?? null,
                'scored_by' => $fullData['scored_by'] ?? $seasonalEntry['members'] ?? null,
                'rank' => $fullData['rank'] ?? null,
                'popularity' => $fullData['popularity'] ?? null,
                'members' => $fullData['members'] ?? $seasonalEntry['members'] ?? null,
                'favorites' => $fullData['favorites'] ?? 0,
                'synopsis' => $fullData['synopsis'] ?? $seasonalEntry['synopsis'] ?? null,
                'background' => $fullData['background'] ?? null,
                'premiered' => $fullData['premiered'] ?? null,
                'broadcast' => $fullData['broadcast'] ?? null,
                'producers' => $fullData['producers'] ?? $seasonalEntry['producers'] ?? [],
                'licensors' => $fullData['licensors'] ?? $seasonalEntry['licensors'] ?? [],
                'studios' => $fullData['studios'] ?? [],
                'genres' => $fullData['genres'] ?? $seasonalEntry['genres'] ?? [],
                'explicit_genres' => $fullData['explicit_genres'] ?? $seasonalEntry['explicit_genres'] ?? [],
                'themes' => $fullData['themes'] ?? $seasonalEntry['themes'] ?? [],
                'demographics' => $fullData['demographics'] ?? $seasonalEntry['demographics'] ?? [],
                'r18' => $seasonalEntry['r18'] ?? false,
                'kids' => $seasonalEntry['kids'] ?? false,
                'continuing' => $seasonalEntry['continuing'] ?? false,
            ];
            
        } catch (\Exception $e) {
            // If full fetch fails, return enriched version with available data
            return $this->convertToV4Format($seasonalEntry);
        }
    }

    /**
     * Convert seasonal entry to V4 format without additional API calls.
     * Used as fallback when full anime fetch fails.
     */
    private function convertToV4Format(array $entry): array
    {
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
            'type' => $entry['type'] ?? $this->inferTypeFromAiringStart($entry),
            'source' => null,
            'episodes' => $entry['episodes'] ?? null,
            'status' => $this->determineStatus($entry),
            'airing' => isset($entry['airing_start']),
            'aired' => [
                'from' => $entry['airing_start'] ?? null,
                'to' => null,
                'prop' => ['from' => null, 'to' => null],
                'string' => $entry['airing_start'] ?? '',
            ],
            'duration' => null,
            'rating' => null,
            'score' => $entry['score'] ?? null,
            'scored_by' => $entry['members'] ?? null,
            'rank' => null,
            'popularity' => null,
            'members' => $entry['members'] ?? null,
            'favorites' => 0,
            'synopsis' => $entry['synopsis'] ?? null,
            'background' => null,
            'premiered' => null,
            'broadcast' => null,
            'producers' => $entry['producers'] ?? [],
            'licensors' => $entry['licensors'] ?? [],
            'studios' => [],
            'genres' => $entry['genres'] ?? [],
            'explicit_genres' => $entry['explicit_genres'] ?? [],
            'themes' => $entry['themes'] ?? [],
            'demographics' => $entry['demographics'] ?? [],
            'r18' => $entry['r18'] ?? false,
            'kids' => $entry['kids'] ?? false,
            'continuing' => $entry['continuing'] ?? false,
        ];
    }

    /**
     * Infer anime type from airing start date (heuristic for seasonal entries).
     * Most seasonal anime are TV series unless they have very few episodes.
     */
    private function inferTypeFromAiringStart(array $entry): ?string
    {
        // If type is already set and valid, use it
        if (!empty($entry['type']) && in_array($entry['type'], ['TV', 'OVA', 'Movie', 'Special', 'ONA', 'Music'])) {
            return $entry['type'];
        }
        
        // Infer from episode count
        $episodes = $entry['episodes'] ?? null;
        if ($episodes !== null) {
            if ($episodes == 1) return 'Movie';
            if ($episodes <= 4) return 'Special';
            if ($episodes <= 13) return 'TV';  // Cour-length
            return 'TV';
        }
        
        // Default to TV for seasonal anime
        return 'TV';
    }

    /**
     * Determine status based on seasonal entry data.
     */
    private function determineStatus(array $entry): string
    {
        if (isset($entry['airing_start']) && !empty($entry['airing_start'])) {
            $airingStart = strtotime($entry['airing_start']);
            if ($airingStart && $airingStart <= time()) {
                return 'Currently Airing';
            }
            return 'Not yet aired';
        }
        
        if (isset($entry['continuing']) && $entry['continuing']) {
            return 'Currently Airing';
        }
        
        return 'Unknown';
    }
}
