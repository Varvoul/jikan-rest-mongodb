<?php

namespace App\Http\Controllers\V3;

use App\Http\HttpHelper;
use Jikan\Request\Anime\AnimeCharactersAndStaffRequest;
use Jikan\Request\Anime\AnimeEpisodesRequest;
use Jikan\Request\Anime\AnimeForumRequest;
use Jikan\Request\Anime\AnimeMoreInfoRequest;
use Jikan\Request\Anime\AnimeNewsRequest;
use Jikan\Request\Anime\AnimePicturesRequest;
use Jikan\Request\Anime\AnimeRecentlyUpdatedByUsersRequest;
use Jikan\Request\Anime\AnimeRecommendationsRequest;
use Jikan\Request\Anime\AnimeRequest;
use Jikan\Request\Anime\AnimeReviewsRequest;
use Jikan\Request\Anime\AnimeStatsRequest;
use Jikan\Request\Anime\AnimeVideosRequest;

class AnimeController extends Controller
{
    public function main(int $id)
    {
        $anime = $this->jikan->getAnime(new AnimeRequest($id));
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function full(int $id)
    {
        // Fetch all required data in sequence from MyAnimeList
        // 1. Main anime data
        $mainAnime = $this->jikan->getAnime(new AnimeRequest($id));
        $mainData = json_decode($this->serializer->serialize($mainAnime, 'json'), true);

        // 2. Pictures
        $picturesResult = $this->jikan->getAnimePictures(new AnimePicturesRequest($id));
        $picturesData = json_decode($this->serializer->serialize(['pictures' => $picturesResult], 'json'), true);

        // 3. Characters & Voice Actors (includes main + supporting, Japanese + English)
        $charactersStaffResult = $this->jikan->getAnimeCharactersAndStaff(new AnimeCharactersAndStaffRequest($id));
        $charactersStaffData = json_decode($this->serializer->serialize($charactersStaffResult, 'json'), true);

        // 4. Statistics
        $statsResult = $this->jikan->getAnimeStats(new AnimeStatsRequest($id));
        $statsData = json_decode($this->serializer->serialize($statsResult, 'json'), true);

        // Combine all data into a single response
        $combined = $mainData;

        // Add pictures
        if (isset($picturesData['pictures'])) {
            $combined['pictures'] = $picturesData['pictures'];
        }

        // Add characters and voice actors
        if (isset($charactersStaffData['characters'])) {
            $combined['characters'] = $charactersStaffData['characters'];
        }
        if (isset($charactersStaffData['staff'])) {
            $combined['staff'] = $charactersStaffData['staff'];
        }

        // Add statistics
        foreach ($statsData as $key => $value) {
            if (!isset($combined[$key])) {
                $combined[$key] = $value;
            }
        }

        // === V4-style field transformations ===

        // Transform premiered string ("Fall 2002") → season + year
        if (isset($combined['premiered']) && is_string($combined['premiered']) && $combined['premiered'] !== '') {
            $premiered = $combined['premiered'];
            $seasonMap = [
                'Winter' => 'winter', 'Spring' => 'spring',
                'Summer' => 'summer', 'Fall' => 'fall',
            ];
            if (preg_match('/^(\w+)\s+(\d{4})$/', $premiered, $m)) {
                $seasonName = strtolower($seasonMap[$m[1]] ?? $m[1]);
                $combined['season'] = $seasonName;
                $combined['year'] = (int) $m[2];
                $combined['string'] = $seasonName . '-' . $m[2];
            }
            // Remove the old string field
            unset($combined['premiered']);
        }

        // Transform broadcast string ("Thursdays at 19:30 (JST)") → structured object
        if (isset($combined['broadcast']) && is_string($combined['broadcast']) && $combined['broadcast'] !== '') {
            $broadcast = $combined['broadcast'];
            $broadcastObj = ['string' => $broadcast];

            // Parse "Day at HH:MM (TZ)"
            if (preg_match('/^(\w+)s?\s+at\s+(\d{1,2}:\d{2})\s*\(([^)]+)\)/i', $broadcast, $m)) {
                $broadcastObj['day'] = $m[1];
                $broadcastObj['time'] = $m[2];
                $broadcastObj['timezone'] = $m[3];
            } elseif (preg_match('/^(\w+)s?\s+at\s+(\d{1,2}:\d{2})/i', $broadcast, $m)) {
                $broadcastObj['day'] = $m[1];
                $broadcastObj['time'] = $m[2];
                $broadcastObj['timezone'] = 'Asia/Tokyo';
            } elseif (preg_match('/^(\w+)/', $broadcast, $m)) {
                $broadcastObj['day'] = $m[1];
            }

            $combined['broadcast'] = $broadcastObj;
        }

        return response(json_encode($combined));
    }

    public function characters_staff(int $id)
    {
        $anime = $this->jikan->getAnimeCharactersAndStaff(new AnimeCharactersAndStaffRequest($id));
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function episodes(int $id, int $page = 1)
    {
        $perPage = 100;
        
        // First, try to get episodes using the Jikan parser (may return empty due to MAL changes)
        $episodesResult = $this->jikan->getAnimeEpisodes(new AnimeEpisodesRequest($id, $page));
        $episodesData = json_decode($this->serializer->serialize($episodesResult, 'json'), true);
        $episodes = $episodesData['episodes'] ?? [];
        
        // If Jikan parser returned no data, use custom scraper to get episode info
        if (empty($episodes)) {
            $episodes = $this->scrapeEpisodesFromMAL($id);
        }
        
        $totalEpisodes = count($episodes);
        
        // Calculate pagination
        $lastVisiblePage = max(1, (int) ceil($totalEpisodes / $perPage));
        $hasNextPage = $page < $lastVisiblePage;
        
        // Apply pagination to results
        $offset = ($page - 1) * $perPage;
        $paginatedEpisodes = array_slice($episodes, $offset, $perPage);
        
        // Build V4-style paginated response
        $response = [
            'pagination' => [
                'last_visible_page' => $lastVisiblePage,
                'has_next_page' => $hasNextPage,
                'current_page' => $page,
                'items' => [
                    'count' => count($paginatedEpisodes),
                    'total' => $totalEpisodes,
                    'per_page' => $perPage
                ]
            ],
            'data' => array_values($paginatedEpisodes)
        ];
        
        return response(json_encode($response));
    }
    
    /**
     * Custom episode scraper - fetches episode data when Jikan parser fails
     * 
     * Strategy:
     * 1. Get main anime data to find total episode count
     * 2. Try to scrape individual episode details from MAL
     * 3. Generate basic episode entries with available information
     */
    private function scrapeEpisodesFromMAL(int $animeId): array
    {
        $episodes = [];
        
        try {
            // Step 1: Get main anime data to find total episodes
            $animeData = $this->jikan->getAnime(new AnimeRequest($animeId));
            $animeJson = json_decode($this->serializer->serialize($animeData, 'json'), true);
            
            $totalEpisodes = (int) ($animeJson['episodes'] ?? 0);
            $animeTitle = $animeJson['title'] ?? 'Unknown';
            $animeUrl = $animeJson['url'] ?? "https://myanimelist.net/anime/{$animeId}";
            
            // If no episodes, return empty
            if ($totalEpisodes <= 0) {
                return $episodes;
            }
            
            // Step 2: Try to scrape detailed episode info from MAL
            $client = app('GuzzleClient');
            
            // Try the episode page first (may require login on newer MAL)
            try {
                $response = $client->get("https://myanimelist.net/anime/{$animeId}/_/episode", [
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                        'Accept-Language' => 'en-US,en;q=0.5',
                    ],
                    'timeout' => 10,
                ]);
                
                $html = (string) $response->getBody();
                $episodes = $this->parseEpisodeTable($html, $animeId, $animeTitle);
                
                // If we got episodes from parsing, return them
                if (!empty($episodes)) {
                    return $episodes;
                }
            } catch (\Exception $e) {
                // Episode page failed, continue with fallback
            }
            
            // Step 3: Fallback - generate basic episode entries based on total count
            // This ensures users always see episode data even if we can't get details
            for ($i = 1; $i <= min($totalEpisodes, 500); $i++) { // Cap at 500 to prevent huge responses
                $episodes[] = [
                    'mal_id' => $i,
                    'url' => "{$animeUrl}/episode/{$i}",
                    'title' => "Episode {$i}",
                    'title_japanese' => null,
                    'title_romanji' => null,
                    'aired' => null,
                    'score' => null,
                    'filler' => false,
                    'recap' => false,
                    'summary' => null,
                    'forum_url' => null,
                ];
            }
            
        } catch (\Exception $e) {
            // If everything fails, return empty array
            // Log error in production
        }
        
        return $episodes;
    }
    
    /**
     * Parse episode table from MAL HTML response
     */
    private function parseEpisodeTable(string $html, int $animeId, string $animeTitle): array
    {
        $episodes = [];
        
        // MAL uses a table structure for episodes with class "episode-list"
        // Pattern: <tr> with episode data including title, aired date, etc.
        preg_match_all(
            '#<tr[^>]*class="[^"]*episode-list-data[^"]*"[^>]*>(.*?)</tr>#si',
            $html,
            $rows,
            PREG_SET_ORDER
        );
        
        foreach ($rows as $index => $row) {
            $rowHtml = $row[1];
            
            // Extract episode number
            $epNum = $index + 1;
            if (preg_match('#<td[^>]*class="[^"]*episode-number[^"]*"[^>]*>\s*(\d+)\s*</td>#si', $rowHtml, $m)) {
                $epNum = (int) $m[1];
            }
            
            // Extract episode title
            $title = "Episode {$epNum}";
            if (preg_match('#<td[^>]*class="[^"]*episode-title[^"]*"[^>]*>\s*(?:<a[^>]*>)?\s*(.*?)\s*(?:</a>)?\s*</td>#si', $rowHtml, $m)) {
                $rawTitle = strip_tags($m[1]);
                $rawTitle = html_entity_decode($rawTitle, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $rawTitle = trim(preg_replace('/\s+/', ' ', $rawTitle));
                if (!empty($rawTitle)) {
                    $title = $rawTitle;
                }
            }
            
            // Extract aired date (premiere date)
            $aired = null;
            if (preg_match('#<td[^>]*class="[^"]*episode-aired[^"]*"[^>]*>\s*(.*?)\s*</td>#si', $rowHtml, $m)) {
                $airedRaw = strip_tags($m[1]);
                $airedRaw = trim($airedRaw);
                if (!empty($airedRaw) && strtolower($airedRaw) !== 'n/a') {
                    $aired = $airedRaw;
                }
            }
            
            // Check for filler/recap markers
            $filler = (bool) preg_match('/filler/i', $rowHtml);
            $recap = (bool) preg_match('/recap/i', $rowHtml);
            
            // Extract score/rating if available
            $score = null;
            if (preg_match('#([\d.]+)\s*</td>\s*$#si', $rowHtml, $m)) {
                $score = (float) $m[1];
            }
            
            // Extract summary/forum link
            $summary = null;
            $forumUrl = null;
            if (preg_match('#href="([^"]*myanimelist\.net/forum[^"]*)"#si', $rowHtml, $m)) {
                $forumUrl = $m[1];
            }
            
            $episodes[] = [
                'mal_id' => $epNum,
                'url' => "https://myanimelist.net/anime/{$animeId}/episode/{$epNum}",
                'title' => $title,
                'title_japanese' => null,
                'title_romanji' => null,
                'aired' => $aired,
                'score' => $score,
                'filler' => $filler,
                'recap' => $recap,
                'summary' => $summary,
                'forum_url' => $forumUrl,
            ];
        }
        
        // Alternative pattern: newer MAL structure might use different classes
        if (empty($episodes)) {
            preg_match_all(
                '#<tr[^>]*>(.*?)</tr>#si',
                $html,
                $allRows,
                PREG_SET_ORDER
            );
            
            foreach ($allRows as $index => $row) {
                $rowHtml = $row[1];
                
                // Look for rows that contain episode-like content
                if (preg_match('/(Episode|Ep\.)/i', $rowHtml) || preg_match('#<td>\s*\d+\s*</td>#', $rowHtml)) {
                    $epNum = $index + 1;
                    
                    // Try to extract title
                    $title = "Episode {$epNum}";
                    if (preg_match('#(?:Episode|Ep\.)\s*(\d+)[:\s]*(.*?)(?:<|$)#si', $rowHtml, $m)) {
                        if (!empty($m[1])) $epNum = (int) $m[1];
                        if (!empty($m[2])) $title = trim(strip_tags($m[2]));
                    }
                    
                    $episodes[] = [
                        'mal_id' => $epNum,
                        'url' => "https://myanimelist.net/anime/{$animeId}/episode/{$epNum}",
                        'title' => $title,
                        'title_japanese' => null,
                        'title_romanji' => null,
                        'aired' => null,
                        'score' => null,
                        'filler' => false,
                        'recap' => false,
                        'summary' => null,
                        'forum_url' => null,
                    ];
                }
            }
        }
        
        return $episodes;
    }

    public function news(int $id)
    {
        $anime = ['articles' => $this->jikan->getNewsList(new AnimeNewsRequest($id))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function forum(int $id, ?string $topic = null)
    {
        if ($topic === 'episodes') {
            $topic = 'episode';
        }

        $anime = ['topics' => $this->jikan->getAnimeForum(new AnimeForumRequest($id, $topic))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function videos(int $id)
    {
        $anime = $this->jikan->getAnimeVideos(new AnimeVideosRequest($id));
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function pictures(int $id)
    {
        $anime = ['pictures' => $this->jikan->getAnimePictures(new AnimePicturesRequest($id))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function stats(int $id)
    {
        $anime = $this->jikan->getAnimeStats(new AnimeStatsRequest($id));
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function moreInfo(int $id)
    {
        $anime = ['moreinfo' => $this->jikan->getAnimeMoreInfo(new AnimeMoreInfoRequest($id))];
        return response(json_encode($anime));
    }

    public function recommendations(int $id)
    {
        // Fetch raw recommendations
        $recommendations = $this->jikan->getAnimeRecommendations(new AnimeRecommendationsRequest($id));
        $recommendationsData = json_decode($this->serializer->serialize(['recommendations' => $recommendations], 'json'), true);
        
        // Transform each recommendation to include all required V4 fields
        $transformedRecommendations = [];
        
        foreach ($recommendationsData['recommendations'] ?? [] as $rec) {
            // Fetch full anime data for this recommendation to get type, duration, rating
            $recAnimeId = $rec['mal_id'] ?? null;
            
            // Build base recommendation entry
            $entry = [
                'mal_id' => $rec['mal_id'] ?? null,
                'url' => $rec['url'] ?? null,
                'images' => $rec['images'] ?? [
                    'jpg' => ['image_url' => $rec['image_url'] ?? null],
                    'webp' => ['image_url' => $rec['image_url'] ?? null, 'small_image_url' => null]
                ],
                'title' => $rec['title'] ?? null,
                'recommendation_count' => $rec['recommendation_count'] ?? 0,
                'recommendation_url' => $rec['recommendation_url'] ?? null,
            ];
            
            // Add titles array in V4 format
            $entry['titles'] = [
                ['type' => 'Default', 'title' => $rec['title'] ?? null],
            ];
            if (!empty($rec['title_japanese'])) {
                $entry['titles'][] = ['type' => 'Japanese', 'title' => $rec['title_japanese']];
            }
            
            // Try to fetch additional anime data for required fields (type, duration, rating)
            if ($recAnimeId) {
                try {
                    $animeDetails = $this->jikan->getAnime(new AnimeRequest($recAnimeId));
                    $animeDetailsData = json_decode($this->serializer->serialize($animeDetails, 'json'), true);
                    
                    // Add required fields from full anime data
                    $entry['type'] = $animeDetailsData['type'] ?? null;
                    $entry['duration'] = $animeDetailsData['duration'] ?? null;
                    $entry['rating'] = $animeDetailsData['rating'] ?? null;
                    
                    // Update images with better quality data if available
                    if (isset($animeDetailsData['images'])) {
                        $entry['images'] = $animeDetailsData['images'];
                    }
                    
                    // Update titles with better data
                    if (isset($animeDetailsData['titles']) && is_array($animeDetailsData['titles'])) {
                        $entry['titles'] = $animeDetailsData['titles'];
                    } elseif (isset($animeDetailsData['title'])) {
                        $entry['titles'] = [
                            ['type' => 'Default', 'title' => $animeDetailsData['title']]
                        ];
                        if (!empty($animeDetailsData['title_japanese'])) {
                            $entry['titles'][] = ['type' => 'Japanese', 'title' => $animeDetailsData['title_japanese']];
                        }
                        if (!empty($animeDetailsData['title_english'])) {
                            $entry['titles'][] = ['type' => 'English', 'title' => $animeDetailsData['title_english']];
                        }
                    }
                } catch (\Exception $e) {
                    // If we can't fetch details, use defaults
                    $entry['type'] = $entry['type'] ?? null;
                    $entry['duration'] = $entry['duration'] ?? null;
                    $entry['rating'] = $entry['rating'] ?? null;
                }
            } else {
                // No anime ID available, set defaults
                $entry['type'] = null;
                $entry['duration'] = null;
                $entry['rating'] = null;
            }
            
            $transformedRecommendations[] = $entry;
        }
        
        // Build V4-style response
        $response = [
            'data' => $transformedRecommendations,
            'pagination' => [
                'last_visible_page' => 1,
                'has_next_page' => false
            ]
        ];
        
        return response(json_encode($response));
    }

    public function userupdates(int $id, int $page = 1)
    {
        $anime = ['users' => $this->jikan->getAnimeRecentlyUpdatedByUsers(new AnimeRecentlyUpdatedByUsersRequest($id, $page))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function reviews(int $id, int $page = 1)
    {
        $anime = ['reviews' => $this->jikan->getAnimeReviews(new AnimeReviewsRequest($id, $page))];
        return response($this->serializer->serialize($anime, 'json'));
    }
}