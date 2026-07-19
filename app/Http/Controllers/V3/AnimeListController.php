<?php

namespace App\Http\Controllers\V3;

use Illuminate\Http\Request;
use Jikan\Request\Anime\AnimeRequest;

class AnimeListController extends Controller
{
    /**
     * GET /anime — List all anime from MAL browse page
     * Supports: page, limit, type, status, min_score, q, producer, genres,
     *            start_date, end_date, rated, order_by, sort, sfw
     */
    public function index(Request $request)
    {
        // ── Parse & validate query parameters ──────────────────────────
        $page     = max(1, (int) $request->get('page', 1));
        $limit    = min(25, max(1, (int) $request->get('limit', 25)));
        $type     = $request->get('type');
        $status   = $request->get('status');
        $score    = $request->get('min_score');
        $q        = $request->get('q');
        $sfw      = filter_var($request->get('sfw', false), FILTER_VALIDATE_BOOLEAN);
        $orderBy  = $request->get('order_by', '');
        $sort     = strtolower($request->get('sort', ''));
        $producer = $request->get('producer');
        $genres   = $request->get('genres');
        $startDate= $request->get('start_date');
        $endDate  = $request->get('end_date');
        $rated    = $request->get('rated');
        $letter   = $request->get('letter');

        // ── Map our page/limit → MAL's show offset (50 per page) ──────
        $malPage    = (int) floor(($page - 1) * $limit / 50);
        $sliceStart = (($page - 1) * $limit) % 50;
        $malOffset  = $malPage * 50;

        // ── Build MAL browse URL parameters ───────────────────────────
        $params = ['show' => $malOffset];

        // Type: TV=1, OVA=2, Movie=3, Special=4, ONA=5, Music=6
        $typeMap = [
            'tv' => 1, 'ova' => 2, 'movie' => 3, 'special' => 4,
            'ona' => 5, 'music' => 6
        ];
        if ($type && isset($typeMap[strtolower($type)])) {
            $params['type'] = $typeMap[strtolower($type)];
        }

        // Status: airing=1, finished_airing/completed=2, not_yet_aired/upcoming=3
        $statusMap = [
            'airing' => 1, 'completed' => 2, 'finished_airing' => 2,
            'upcoming' => 3, 'not_yet_aired' => 3
        ];
        if ($status && isset($statusMap[strtolower($status)])) {
            $params['status'] = $statusMap[strtolower($status)];
        }

        // Minimum score
        if ($score !== null) {
            $s = (float) $score;
            if ($s >= 0 && $s <= 10) {
                $params['score'] = (int) $s;
            }
        }

        // Search query
        if ($q) {
            $params['q'] = $q;
        }

        // Letter (starts with)
        if ($letter !== null && $letter !== '') {
            $params['letter'] = mb_substr($letter, 0, 1, 'UTF-8');
        }

        // Rated: g=1, pg=2, pg13=3, r17=4, r+=5, rx=6
        $ratedMap = [
            'g' => 1, 'pg' => 2, 'pg13' => 3, 'r17' => 4, 'r+' => 5, 'rx' => 6,
            'pg-13' => 3, 'r - 17+ (violence & profanity)' => 4,
            'r+ - mild nudity' => 5, 'rx - hentai' => 6,
            'g - all ages' => 1, 'pg - children' => 2,
            'pg-13 - teens 13 or older' => 3
        ];
        if ($rated && isset($ratedMap[strtolower($rated)])) {
            $params['p'] = $ratedMap[strtolower($rated)];
        }

        // Producer
        if ($producer) {
            $params['mid'] = (int) $producer;
        }

        // Genre
        if ($genres) {
            $params['genre'] = (int) $genres;
        }

        // SFW: exclude erotica genres (9, 12, 41 - Hentai, Erotica, etc.)
        if ($sfw) {
            $params['c[0]'] = 'a';
            $params['c[1]'] = 'b';
            $params['c[2]'] = 'c';
            $params['c[3]'] = 'd';
            // Exclude genre 9 (Hentai), 49 (Erotica)
        }

        // Start date
        if ($startDate && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $startDate, $m)) {
            $params['sy'] = (int) $m[1];
            $params['sm'] = (int) $m[2];
            $params['sd'] = (int) $m[3];
        }

        // End date
        if ($endDate && preg_match('/(\d{4})-(\d{2})-(\d{2})/', $endDate, $m)) {
            $params['ey'] = (int) $m[1];
            $params['em'] = (int) $m[2];
            $params['ed'] = (int) $m[3];
        }

        // Order by → MAL's 'o' parameter
        // 0=default, 2=title?, 3=start_date?, etc.
        $orderMap = [
            'title'      => 2,
            'start_date' => 3,
            'score'      => 2,
            'episodes'   => 4,
            'members'    => 0,
            'id'         => 0,
        ];
        if ($orderBy && isset($orderMap[strtolower($orderBy)])) {
            $params['o'] = $orderMap[strtolower($orderBy)];
        }

        // ── Scrape MAL browse page ────────────────────────────────────
        $url = 'https://myanimelist.net/anime.php?' . http_build_query($params);

        try {
            $client = app('GuzzleClient');
            $response = $client->get($url, [
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ]
            ]);
            $html = (string) $response->getBody();
        } catch (\Exception $e) {
            return response(json_encode([
                'status' => 500,
                'type' => 'Exception',
                'message' => 'Failed to reach MyAnimeList: ' . $e->getMessage(),
                'error' => null,
            ]), 500);
        }

        // ── Parse anime entries from HTML ──────────────────────────────
        $animeEntries = $this->parseBrowsePage($html);

        if (empty($animeEntries)) {
            return response(json_encode([
                'pagination' => [
                    'last_visible_page' => 1,
                    'has_next_page' => false,
                    'current_page' => $page,
                    'items' => ['count' => 0, 'total' => 0, 'per_page' => $limit],
                ],
                'data' => [],
            ]));
        }

        // ── Slice for current page ─────────────────────────────────────
        $pageEntries = array_slice($animeEntries, $sliceStart, $limit);

        // ── Fetch full anime data via Jikan parser (uses MongoDB cache) ─
        $animeList = [];
        foreach ($pageEntries as $entry) {
            try {
                $anime = $this->jikan->getAnime(new AnimeRequest((int) $entry['mal_id']));
                $raw = json_decode($this->serializer->serialize($anime, 'json'), true);
                $animeList[] = $this->transformToV4($raw);
            } catch (\Exception $e) {
                // Full fetch failed — use browse page data as lightweight fallback
                $animeList[] = $this->browseEntryToV4Lite($entry);
            }
        }

        // ── Build pagination ───────────────────────────────────────────
        $total = $this->extractTotalCount($html);
        $lastPage = max(1, (int) ceil($total / $limit));

        $response = [
            'pagination' => [
                'last_visible_page' => $lastPage,
                'has_next_page'     => $page < $lastPage,
                'current_page'      => $page,
                'items'             => [
                    'count'    => count($animeList),
                    'total'    => $total,
                    'per_page' => $limit,
                ],
            ],
            'data' => $animeList,
        ];
        return response(json_encode($response));
    }

    // ─── HTML parsing ──────────────────────────────────────────────────

    private function parseBrowsePage(string $html): array
    {
        $entries = [];
        $seen = [];

        // Extract anime entries from table rows
        // Each row: <tr> ... <td>image</td> <td>title+synopsis</td> <td>type</td> <td>eps</td> <td>score</td> </tr>
        preg_match_all(
            '#<tr[^>]*>\s*<td[^>]*>\s*<div class="picSurround">(.*?)</tr>#si',
            $html,
            $rows,
            PREG_SET_ORDER
        );

        foreach ($rows as $row) {
            $rowHtml = $row[1];

            // Extract mal_id from link
            if (!preg_match('#/anime/(\d+)/#', $rowHtml, $idMatch)) {
                continue;
            }
            $id = (int) $idMatch[1];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            // Title from <strong> tag
            $title = '';
            if (preg_match('#<strong>([^<]+)</strong>#', $rowHtml, $tMatch)) {
                $title = html_entity_decode($tMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            // Image URL (remove /r/50x70/ sizing prefix and query string)
            $imageUrl = '';
            if (preg_match('#data-src="([^"]*)"#', $rowHtml, $imgMatch)) {
                $imageUrl = $imgMatch[1];
            }

            // Type (TV, Movie, OVA, etc.)
            $type = null;
            if (preg_match('#<td[^>]*>\s*(TV|OVA|Movie|Special|ONA|Music|CM|PV|TV Special)\s*</td>#si', $rowHtml, $typeMatch)) {
                $type = trim($typeMatch[1]);
            }

            // Episodes
            $episodes = null;
            if (preg_match('#<td[^>]*width="40"[^>]*>\s*([\d?]+|N/A)\s*</td>#si', $rowHtml, $epsMatch)) {
                $epVal = trim($epsMatch[1]);
                $episodes = ($epVal === 'N/A' || $epVal === '?') ? null : (int) $epVal;
            }

            // Score
            $score = null;
            if (preg_match('#<td[^>]*width="50"[^>]*>\s*([\d.]+|N/A)\s*</td>#si', $rowHtml, $scoreMatch)) {
                $scoreVal = trim($scoreMatch[1]);
                $score = ($scoreVal === 'N/A') ? null : (float) $scoreVal;
            }

            // Synopsis (from .pt4 div, strip HTML tags)
            $synopsis = null;
            if (preg_match('#class="pt4">(.*?)</div>#si', $rowHtml, $synMatch)) {
                $synRaw = preg_replace('#<[^>]+>#', ' ', $synMatch[1]);
                $synRaw = preg_replace('#\s+#', ' ', $synRaw);
                $synRaw = html_entity_decode($synRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $synRaw = preg_replace('#\s*read more\.\s*$#i', '', $synRaw);
                $synopsis = trim($synRaw);
                if ($synopsis === '') {
                    $synopsis = null;
                }
            }

            // Members
            $members = null;
            if (preg_match('#([\d,]+)\s*members?#i', $rowHtml, $memMatch)) {
                $members = (int) str_replace(',', '', $memMatch[1]);
            }

            $entries[] = [
                'mal_id'   => $id,
                'title'    => $title,
                'image_url'=> $imageUrl,
                'type'     => $type,
                'episodes' => $episodes,
                'score'    => $score,
                'synopsis' => $synopsis,
                'members'  => $members,
            ];
        }

        return $entries;
    }

    private function extractTotalCount(string $html): int
    {
        // Try to find total count text
        if (preg_match('/(\d[\d,]+)\s*titles?/i', $html, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }

        // Calculate from pagination links: find max 'show=N' offset
        preg_match_all('/show=(\d+)/', $html, $offsets);
        if (!empty($offsets[1])) {
            $maxOffset = max(array_map('intval', $offsets[1]));
            // Check if there's a '...' indicating more pages beyond what's shown
            $hasMore = preg_match('/>\s*\.\.\.\s*</', $html);
            if ($hasMore) {
                // MAL shows ~20 page links; estimate total from max visible offset
                // Typical MAL has ~600 pages for all anime (30,000 / 50)
                return $maxOffset + 100; // rough estimate, enough for pagination
            }
            return $maxOffset + 50;
        }

        return count($this->parseBrowsePage($html));
    }

    // ─── V3 → V4 format transformation ────────────────────────────────

    private function transformToV4(array $a): array
    {
        // Strip Jikan v3 request metadata if present
        unset($a['request_hash'], $a['request_cached'], $a['request_cache_expiry']);

        // ── Images object ──
        $imageUrl = $a['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);

        // ── Titles array ──
        $titles = [
            ['type' => 'Default',  'title' => $a['title'] ?? ''],
        ];
        if (!empty($a['title_japanese'])) {
            $titles[] = ['type' => 'Japanese', 'title' => $a['title_japanese']];
        }
        if (!empty($a['title_english'])) {
            $titles[] = ['type' => 'English', 'title' => $a['title_english']];
        }
        foreach ($a['title_synonyms'] ?? [] as $syn) {
            $titles[] = ['type' => 'Synonym', 'title' => $syn];
        }

        // ── Broadcast object ──
        $broadcast = null;
        if (isset($a['broadcast'])) {
            if (is_array($a['broadcast'])) {
                $broadcast = $a['broadcast'];
            } elseif (is_string($a['broadcast']) && $a['broadcast'] !== '') {
                $broadcast = $this->parseBroadcast($a['broadcast']);
            }
        }

        // ── Season + Year ──
        $season = null;
        $year   = null;
        if (isset($a['season']) && (is_string($a['season']) || is_int($a['season'])) && $a['season'] !== '' && $a['season'] !== false) {
            $season = $a['season'];
        }
        if (isset($a['year']) && is_numeric($a['year']) && $a['year'] !== false) {
            $year = (int) $a['year'];
        }
        if (($season === null || $year === null) && !empty($a['premiered']) && is_string($a['premiered'])) {
            $sMap = ['Winter' => 'winter', 'Spring' => 'spring', 'Summer' => 'summer', 'Fall' => 'fall'];
            if (preg_match('/^(\w+)\s+(\d{4})$/', $a['premiered'], $pm)) {
                $season = $sMap[$pm[1]] ?? strtolower($pm[1]);
                $year   = (int) $pm[2];
            }
        }

        // ── Build v4 item ──
        $v4 = [
            'mal_id'          => $a['mal_id'] ?? null,
            'url'             => $a['url'] ?? '',
            'images'          => $images,
            'trailer'         => [
                'youtube_id'  => $a['trailer_url'] ?? ($a['trailer']['youtube_id'] ?? null),
                'url'         => $a['trailer_url'] ?? null,
                'embed_url'   => null,
                'images'      => [
                    'image_url'        => null,
                    'small_image_url'  => null,
                    'medium_image_url' => null,
                    'large_image_url'  => null,
                    'maximum_image_url'=> null,
                ],
            ],
            'approved'        => true,
            'titles'          => $titles,
            'title'           => $a['title'] ?? '',
            'title_english'   => $a['title_english'] ?? null,
            'title_japanese'  => $a['title_japanese'] ?? null,
            'title_synonyms'  => $a['title_synonyms'] ?? [],
            'type'            => $a['type'] ?? null,
            'source'          => $a['source'] ?? null,
            'episodes'        => $a['episodes'] ?? null,
            'status'          => $a['status'] ?? null,
            'airing'          => $a['airing'] ?? false,
            'aired'           => $a['aired'] ?? null,
            'duration'        => $a['duration'] ?? null,
            'rating'          => $a['rating'] ?? null,
            'score'           => $a['score'] ?? null,
            'scored_by'       => $a['scored_by'] ?? null,
            'rank'            => $a['rank'] ?? null,
            'popularity'      => $a['popularity'] ?? null,
            'members'         => $a['members'] ?? null,
            'favorites'       => $a['favorites'] ?? null,
            'synopsis'        => $a['synopsis'] ?? null,
            'background'      => $a['background'] ?? null,
            'season'          => $season,
            'year'            => $year,
            'broadcast'       => $broadcast,
            'producers'       => $a['producers'] ?? [],
            'licensors'       => $a['licensors'] ?? [],
            'studios'         => $a['studios'] ?? [],
            'genres'          => $a['genres'] ?? [],
            'explicit_genres' => $a['explicit_genres'] ?? [],
            'themes'          => $a['themes'] ?? [],
            'demographics'    => $a['demographics'] ?? [],
        ];

        return $v4;
    }

    /**
     * Lightweight fallback when full Jikan parse fails — uses browse page data.
     */
    private function browseEntryToV4Lite(array $entry): array
    {
        return [
            'mal_id'          => $entry['mal_id'],
            'url'             => "https://myanimelist.net/anime/{$entry['mal_id']}",
            'images'          => $this->buildImagesObject($entry['image_url'] ?? ''),
            'trailer'         => [
                'youtube_id'  => null, 'url' => null, 'embed_url' => null,
                'images'      => [
                    'image_url' => null, 'small_image_url' => null,
                    'medium_image_url' => null, 'large_image_url' => null,
                    'maximum_image_url' => null,
                ],
            ],
            'approved'        => true,
            'titles'          => [['type' => 'Default', 'title' => $entry['title']]],
            'title'           => $entry['title'],
            'title_english'   => null,
            'title_japanese'  => null,
            'title_synonyms'  => [],
            'type'            => $entry['type'] ?? null,
            'source'          => null,
            'episodes'        => $entry['episodes'] ?? null,
            'status'          => null,
            'airing'          => false,
            'aired'           => null,
            'duration'        => null,
            'rating'          => null,
            'score'           => isset($entry['score']) ? (float) $entry['score'] : null,
            'scored_by'       => null,
            'rank'            => null,
            'popularity'      => null,
            'members'         => null,
            'favorites'       => null,
            'synopsis'        => $entry['synopsis'] ?? null,
            'background'      => null,
            'season'          => null,
            'year'            => null,
            'broadcast'       => null,
            'producers'       => [],
            'licensors'       => [],
            'studios'         => [],
            'genres'          => [],
            'explicit_genres' => [],
            'themes'          => [],
            'demographics'    => [],
        ];
    }

    // ─── Helper methods ────────────────────────────────────────────────

    private function buildImagesObject(string $url): array
    {
        if (empty($url)) {
            return [
                'jpg'  => ['image_url' => null, 'small_image_url' => null, 'large_image_url' => null],
                'webp' => ['image_url' => null, 'small_image_url' => null, 'large_image_url' => null],
            ];
        }

        // Remove any query strings and sizing prefixes
        $baseUrl = preg_replace('#/r/\d+x\d+/#', '/', $url);
        $baseUrl = preg_replace('#\?.*$#', '', $baseUrl);

        // Build jpg variants
        $jpgBase = preg_replace('#\.(webp|jpg|jpeg|png|gif)$#i', '', $baseUrl);
        $jpg = [
            'image_url'        => $jpgBase . '.jpg',
            'small_image_url'  => $jpgBase . 't.jpg',
            'large_image_url'  => $jpgBase . 'l.jpg',
        ];

        // Build webp variants
        $webp = [
            'image_url'        => $jpgBase . '.webp',
            'small_image_url'  => $jpgBase . 't.webp',
            'large_image_url'  => $jpgBase . 'l.webp',
        ];

        return ['jpg' => $jpg, 'webp' => $webp];
    }

    private function parseBroadcast(string $str): array
    {
        $obj = ['string' => $str, 'day' => null, 'time' => null, 'timezone' => null];

        if (preg_match('/^(\w+)s?\s+at\s+(\d{1,2}:\d{2})\s*\(([^)]+)\)/i', $str, $m)) {
            $obj['day']      = $m[1];
            $obj['time']     = $m[2];
            $obj['timezone'] = $m[3];
        } elseif (preg_match('/^(\w+)s?\s+at\s+(\d{1,2}:\d{2})/i', $str, $m)) {
            $obj['day']      = $m[1];
            $obj['time']     = $m[2];
            $obj['timezone'] = 'Asia/Tokyo';
        } elseif (preg_match('/^(\w+)/', $str, $m)) {
            $obj['day'] = $m[1];
        }

        return $obj;
    }
}
