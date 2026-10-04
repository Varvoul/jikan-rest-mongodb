<?php

namespace App\Http\Controllers\V4;

use App\Http\Controllers\V3\Controller as V3Controller;
use Illuminate\Http\Request;
use Jikan\Request\Top\TopAnimeRequest;
use Jikan\Request\Top\TopMangaRequest;
use Jikan\Request\Seasonal\SeasonalRequest;
use Jikan\Request\Search\AnimeSearchRequest;
use Jikan\Request\Search\MangaSearchRequest;
use Jikan\Helper\Constants as JikanConstants;

class ListController extends V3Controller
{
    private const NSFW_GENRE_IDS = [12, 49]; // Hentai=12, Erotica=49
    private const DEFAULT_LIMIT = 25;
    private const MAX_LIMIT = 25;

    private const TOP_ANIME_FILTER_MAP = [
        'airing'        => 'airing',
        'upcoming'      => 'upcoming',
        'tv'            => 'tv',
        'movie'         => 'movie',
        'ova'           => 'ova',
        'special'       => 'special',
        'ona'           => 'ona',
        'bypopularity'  => 'bypopularity',
        'favorite'      => 'favorite',
    ];

    private const TOP_MANGA_FILTER_MAP = [
        'manga'         => 'manga',
        'novels'        => 'novels',
        'oneshots'      => 'oneshots',
        'doujin'        => 'doujin',
        'manhwa'        => 'manhwa',
        'manhua'        => 'manhua',
        'lightnovels'   => 'lightnovels',
        'bypopularity'  => 'bypopularity',
        'favorite'      => 'favorite',
    ];

    /**
     * GET /v4/anime?page=1&limit=25&sfw=true&status=complete&type=tv&order_by=id&sort=desc
     */
    public function anime(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));

        $searchRequest = new AnimeSearchRequest();
        $searchRequest->setStartsWithChar('');
        $searchRequest->setPage($page);

        // Status filter
        $status = $request->get('status');
        if ($status !== null) {
            $map = [
                'airing'    => JikanConstants::SEARCH_ANIME_STATUS_AIRING,
                'complete'  => JikanConstants::SEARCH_ANIME_STATUS_COMPLETED,
                'completed' => JikanConstants::SEARCH_ANIME_STATUS_COMPLETED,
                'upcoming'  => JikanConstants::SEARCH_ANIME_STATUS_TBA,
            ];
            $s = strtolower($status);
            if (isset($map[$s])) {
                $searchRequest->setStatus($map[$s]);
            }
        }

        // Type filter
        $type = $request->get('type');
        if ($type !== null) {
            $map = [
                'tv' => JikanConstants::SEARCH_ANIME_TV,
                'ova' => JikanConstants::SEARCH_ANIME_OVA,
                'movie' => JikanConstants::SEARCH_ANIME_MOVIE,
                'special' => JikanConstants::SEARCH_ANIME_SPECIAL,
                'ona' => JikanConstants::SEARCH_ANIME_ONA,
                'music' => JikanConstants::SEARCH_ANIME_MUSIC,
            ];
            $t = strtolower($type);
            if (isset($map[$t])) {
                $searchRequest->setType($map[$t]);
            }
        }

        // Order by
        $orderBy = $request->get('order_by', 'id');
        $orderMap = [
            'title'      => JikanConstants::SEARCH_ANIME_ORDER_BY_TITLE,
            'start_date' => JikanConstants::SEARCH_ANIME_ORDER_BY_START_DATE,
            'score'      => JikanConstants::SEARCH_ANIME_ORDER_BY_SCORE,
            'episodes'   => JikanConstants::SEARCH_ANIME_ORDER_BY_EPISODES,
            'members'    => JikanConstants::SEARCH_ANIME_ORDER_BY_MEMBERS,
            'id'         => JikanConstants::SEARCH_ANIME_ORDER_BY_ID,
        ];
        $o = strtolower($orderBy);
        if (isset($orderMap[$o])) {
            $searchRequest->setOrderBy($orderMap[$o]);
        }

        // Sort direction
        $sort = $request->get('sort', 'desc');
        $searchRequest->setSort(
            strtolower($sort) === 'asc'
                ? JikanConstants::SEARCH_SORT_ASCENDING
                : JikanConstants::SEARCH_SORT_DESCENDING
        );

        return $this->fetchSearchResponse($searchRequest, 'getAnimeSearch', $page, $limit, $sfw);
    }

    /**
     * GET /v4/manga?page=1&limit=25&sfw=true
     */
    public function manga(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));

        $searchRequest = new MangaSearchRequest();
        $searchRequest->setStartsWithChar('');
        $searchRequest->setPage($page);

        return $this->fetchSearchResponse($searchRequest, 'getMangaSearch', $page, $limit, $sfw);
    }

    /**
     * GET /v4/seasons/now?page=1&limit=25&sfw=true
     */
    public function seasonsNow(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));

        return $this->fetchSeasonResponse(false, $page, $limit, $sfw);
    }

    /**
     * GET /v4/seasons/upcoming?page=1&limit=25&sfw=true
     */
    public function seasonsUpcoming(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));

        return $this->fetchSeasonResponse(true, $page, $limit, $sfw);
    }

    /**
     * GET /v4/top/anime?page=1&limit=25&sfw=true&filter=airing
     */
    public function topAnime(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));
        $filter = $request->get('filter');

        $type = null;
        if ($filter !== null) {
            $type = self::TOP_ANIME_FILTER_MAP[strtolower($filter)] ?? null;
        }

        try {
            $result = $this->jikan->getTopAnime(new TopAnimeRequest($page, $type));
            $wrapped = ['top' => $result];
            $data = json_decode($this->serializer->serialize($wrapped, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $results = $data['top'] ?? [];
        $lastPage = $this->estimateLastPage($results, $page, 50);

        $results = array_slice($results, 0, $limit);
        if ($sfw) {
            $results = $this->filterSfwByType($results);
        }

        return response($this->buildV4Response($results, $page, $limit, $lastPage));
    }

    /**
     * GET /v4/top/manga?page=1&limit=25&sfw=true&filter=manga
     */
    public function topManga(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));
        $sfw = $this->parseSfw($request->get('sfw'));
        $filter = $request->get('filter');

        $type = null;
        if ($filter !== null) {
            $type = self::TOP_MANGA_FILTER_MAP[strtolower($filter)] ?? null;
        }

        try {
            $result = $this->jikan->getTopManga(new TopMangaRequest($page, $type));
            $wrapped = ['top' => $result];
            $data = json_decode($this->serializer->serialize($wrapped, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $results = $data['top'] ?? [];
        $lastPage = $this->estimateLastPage($results, $page, 50);

        $results = array_slice($results, 0, $limit);
        if ($sfw) {
            $results = $this->filterSfwByType($results);
        }

        return response($this->buildV4Response($results, $page, $limit, $lastPage));
    }

    /**
     * GET /v4/top/characters[/{page}]
     */
    public function topCharacters(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));

        try {
            $result = $this->jikan->getTopCharacters(new \Jikan\Request\Top\TopCharactersRequest($page));
            $wrapped = ['top' => $result];
            $data = json_decode($this->serializer->serialize($wrapped, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $results = $data['top'] ?? [];
        $lastPage = $this->estimateLastPage($results, $page, 50);

        // Transform to V4 format with full fields
        $v4Data = [];
        foreach (array_slice($results, 0, $limit) as $item) {
            $v4Data[] = $this->transformCharacterToV4($item);
        }

        return response($this->buildV4Response($v4Data, $page, $limit, $lastPage));
    }

    /**
     * GET /v4/top/people[/{page}]
     */
    public function topPeople(Request $request)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));

        try {
            $result = $this->jikan->getTopPeople(new \Jikan\Request\Top\TopPeopleRequest($page));
            $wrapped = ['top' => $result];
            $data = json_decode($this->serializer->serialize($wrapped, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $results = $data['top'] ?? [];
        $lastPage = $this->estimateLastPage($results, $page, 50);

        // Transform to V4 format with full fields
        $v4Data = [];
        foreach (array_slice($results, 0, $limit) as $item) {
            $v4Data[] = $this->transformPersonToV4($item);
        }

        return response($this->buildV4Response($v4Data, $page, $limit, $lastPage));
    }

    /**
     * GET /v4/recommendations/anime?page=1&limit=25&sfw=true
     */
    public function recommendationsAnime(Request $request)
    {
        return $this->scrapeRecommendations($request, 'anime');
    }

    /**
     * GET /v4/recommendations/manga?page=1&limit=25&sfw=true
     */
    public function recommendationsManga(Request $request)
    {
        return $this->scrapeRecommendations($request, 'manga');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function fetchSearchResponse($searchRequest, string $method, int $page, int $limit, bool $sfw)
    {
        try {
            $result = $this->jikan->$method($searchRequest);
            $data = json_decode($this->serializer->serialize($result, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $results = $data['results'] ?? [];
        $lastPage = (int)($data['last_page'] ?? $page);

        $results = array_slice($results, 0, $limit);
        if ($sfw) {
            $results = $this->filterSfw($results);
        }

        return response($this->buildV4Response($results, $page, $limit, $lastPage));
    }

    private function fetchSeasonResponse(bool $upcoming, int $page, int $limit, bool $sfw)
    {
        try {
            $result = $this->jikan->getSeasonal(new SeasonalRequest(null, null, $upcoming));
            $data = json_decode($this->serializer->serialize($result, 'json'), true);
        } catch (\Exception $e) {
            return $this->errorResponse($e);
        }

        $allItems = $data['anime'] ?? [];
        $total = count($allItems);
        $lastPage = max(1, (int)ceil($total / $limit));
        $offset = ($page - 1) * $limit;
        $results = array_slice($allItems, $offset, $limit);

        // ✅ FIX: Transform each season anime item to V4 format with proper images object
        $transformedResults = [];
        foreach ($results as $item) {
            $transformedResults[] = $this->transformSeasonAnimeToV4($item);
        }

        if ($sfw) {
            $transformedResults = $this->filterSfw($transformedResults);
        }

        return response($this->buildV4Response($transformedResults, $page, $limit, $lastPage, $total));
    }

    private function scrapeRecommendations(Request $request, string $type)
    {
        $page = max(1, (int)($request->get('page', 1)));
        $limit = $this->clampLimit((int)($request->get('limit', self::DEFAULT_LIMIT)));

        // MAL recommendations page is JavaScript-rendered; Jikan v2 lacks RecentRecommendationsRequest.
        // Returning empty v4 response. Use /v3/{type}/{id}/recommendations for per-entry data.
        return response($this->buildV4Response([], $page, $limit, 1, 0));
    }

    private function buildV4Response(array $data, int $page, int $limit, int $lastPage, ?int $total = null): string
    {
        $count = count($data);
        return json_encode([
            'pagination' => [
                'last_visible_page' => $lastPage,
                'has_next_page'     => $page < $lastPage,
                'current_page'      => $page,
                'items' => [
                    'count'    => $count,
                    'total'    => $total ?? ($lastPage * $limit),
                    'per_page' => $limit,
                ],
            ],
            'data' => array_values($data),
        ]);
    }

    private function filterSfw(array $items): array
    {
        return array_values(array_filter($items, function ($item) {
            // Check rated field: Rx = Hentai
            $rated = $item['rated'] ?? '';
            if (strtolower($rated) === 'rx') {
                return false;
            }

            // Check type field
            $type = strtolower($item['type'] ?? '');
            if ($type === 'hentai') {
                return false;
            }

            // Check all genre arrays for NSFW IDs/names
            foreach (['genres', 'explicit_genres', 'demographics', 'themes'] as $key) {
                foreach ($item[$key] ?? [] as $genre) {
                    $id = (int)($genre['mal_id'] ?? 0);
                    $name = strtolower($genre['name'] ?? '');
                    if (in_array($id, self::NSFW_GENRE_IDS) || in_array($name, ['hentai', 'erotica'])) {
                        return false;
                    }
                }
            }

            return true;
        }));
    }

    private function filterSfwByType(array $items): array
    {
        return array_values(array_filter($items, function ($item) {
            $type = strtolower($item['type'] ?? '');
            return $type !== 'hentai';
        }));
    }

    private function parseSfw($value): bool
    {
        if ($value === null || $value === '' || $value === 'false' || $value === '0') {
            return false;
        }
        return true;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit <= 0) {
            return self::DEFAULT_LIMIT;
        }
        return min($limit, self::MAX_LIMIT);
    }

    private function estimateLastPage(array $results, int $page, int $expectedPerPage): int
    {
        if (count($results) < $expectedPerPage) {
            return $page;
        }
        return $page + 1;
    }

    private function errorResponse(\Exception $e, int $code = 500)
    {
        return response()->json([
            'status'  => $code,
            'type'    => 'Exception',
            'message' => $e->getMessage(),
            'error'   => null,
        ], $code);
    }

    // =========================================================================
    // V3 → V4 Transform Methods for Characters & People
    // =========================================================================

    /**
     * Transform V3 top character item to V4 format with all required fields.
     */
    private function transformCharacterToV4(array $c): array
    {
        // Strip V3 metadata
        unset($c['request_hash'], $c['request_cached'], $c['request_cache_expiry']);

        // Build images object
        $imageUrl = $c['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);

        // Build anime appearances (animeography -> anime)
        $animeAppearances = [];
        foreach ($c['animeography'] ?? [] as $anime) {
            $animeAppearances[] = [
                'role'   => $anime['role'] ?? '',
                'mal_id' => $anime['mal_id'] ?? null,
                'url'    => $anime['url'] ?? '',
                'images' => $this->buildImagesObject($anime['image_url'] ?? ''),
                'name'   => $anime['name'] ?? '',
            ];
        }

        // Build manga appearances (mangaography -> manga)
        $mangaAppearances = [];
        foreach ($c['mangaography'] ?? [] as $manga) {
            $mangaAppearances[] = [
                'role'   => $manga['role'] ?? '',
                'mal_id' => $manga['mal_id'] ?? null,
                'url'    => $manga['url'] ?? '',
                'images' => $this->buildImagesObject($manga['image_url'] ?? ''),
                'name'   => $manga['name'] ?? '',
            ];
        }

        // Build nicknames array
        $nicknames = [];
        if (!empty($c['nickname_japanese'])) {
            $nicknames = is_array($c['nickname_japanese']) ? $c['nickname_japanese'] : [$c['nickname_japanese']];
        }
        if (empty($nicknames) && !empty($c['nicknames'])) {
            $nicknames = is_array($c['nicknames']) ? $c['nicknames'] : [$c['nicknames']];
        }

        // Extract name - try multiple sources in order of preference
        $name = '';
        $url = $c['url'] ?? '';
        
        // PRIMARY: Extract name from URL first (most reliable for MAL data)
        // URL format: https://myanimelist.net/character/417/Lelouch_Lamperouge
        if (strlen($url) > 0 && strpos($url, '/character/') !== false) {
            $matches = [];
            if (preg_match('#/character/\d+/(.+)$#', $url, $matches)) {
                $extracted = str_replace('_', ', ', urldecode($matches[1]));
                if (strlen($extracted) > 0) {
                    $name = $extracted;
                }
            }
        }
        
        // FALLBACK: Try direct 'name' field if URL extraction failed
        if (strlen($name) === 0 && isset($c['name']) && is_string($c['name']) && strlen(trim($c['name'])) > 0) {
            $name = trim($c['name']);
        }
        
        // FALLBACK: Try 'title' field
        if (strlen($name) === 0 && isset($c['title']) && is_string($c['title']) && strlen(trim($c['title'])) > 0) {
            $name = trim($c['title']);
        }
        
        // LAST RESORT: Use mal_id as identifier
        if (strlen($name) === 0 && isset($c['mal_id'])) {
            $name = 'Character #' . $c['mal_id'];
        }

        return [
            'mal_id'     => $c['mal_id'] ?? null,
            'url'        => $c['url'] ?? '',
            'images'     => $images,
            'name'       => $name,
            'name_kanji' => $c['name_kanji'] ?? null,
            'nicknames'  => $nicknames,
            'favorites'  => isset($c['favorites']) ? (int) $c['favorites'] : null,
            'about'      => $c['about'] ?? null,
            'anime'      => $animeAppearances,
            'manga'      => $mangaAppearances,
            'voices'     => [],
        ];
    }

    /**
     * Transform V3 top person item to V4 format with all required fields.
     */
    private function transformPersonToV4(array $p): array
    {
        // Strip V3 metadata
        unset($p['request_hash'], $p['request_cached'], $p['request_cache_expiry']);

        // Build images object
        $imageUrl = $p['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);

        // Build alternative names
        $alternativeNames = [];
        if (!empty($p['family_name']) || !empty($p['given_name'])) {
            if (!empty($p['family_name']) && !empty($p['given_name'])) {
                $alternativeNames[] = $p['family_name'] . ' ' . $p['given_name'];
            }
            if (!empty($p['family_name'])) {
                $alternativeNames[] = $p['family_name'];
            }
            if (!empty($p['given_name'])) {
                $alternativeNames[] = $p['given_name'];
            }
        }
        foreach ($p['alternate_name'] ?? [] as $altName) {
            if (!in_array($altName, $alternativeNames)) {
                $alternativeNames[] = $altName;
            }
        }

        // Build anime staff positions
        $animeStaffPositions = [];
        foreach ($p['anime_staff_positions'] ?? [] as $position) {
            $animeStaffPositions[] = [
                'position' => $position['staff'] ?? $position['position'] ?? '',
                'mal_id'   => $position['mal_id'] ?? null,
                'url'      => $position['url'] ?? '',
                'images'   => $this->buildImagesObject($position['image_url'] ?? ''),
                'name'     => $position['name'] ?? '',
            ];
        }

        // Build voice acting roles
        $voiceActingRoles = [];
        foreach ($p['voice_acting_roles'] ?? [] as $role) {
            $voiceActingRoles[] = [
                'role'   => $role['role'] ?? '',
                'mal_id' => $role['mal_id'] ?? null,
                'url'    => $role['url'] ?? '',
                'images' => $this->buildImagesObject($role['image_url'] ?? ''),
                'name'   => $role['name'] ?? '',
            ];
        }

        return [
            'mal_id'                => $p['mal_id'] ?? null,
            'url'                   => $p['url'] ?? '',
            'images'                => $images,
            'given_name'            => $p['given_name'] ?? null,
            'family_name'           => $p['family_name'] ?? null,
            'alternative_names'     => $alternativeNames ?: ($p['alternate_name'] ?? []),
            'birthday'              => $p['birthday'] ?? null,
            'favorites'             => isset($p['favorites']) ? (int) $p['favorites'] : null,
            'about'                 => $p['about'] ?? null,
            'data'                  => [
                'about'               => $p['about'] ?? null,
                'alternate_names'     => $alternativeNames ?: ($p['alternate_name'] ?? []),
                'voice_acting_roles'  => $voiceActingRoles,
                'anime_staff_positions' => $animeStaffPositions,
                'author_positions'    => [],
            ],
            'anime_staff_positions' => $animeStaffPositions,
            'voice_acting_roles'    => $voiceActingRoles,
            'author_positions'      => [],
        ];
    }

    /**
     * Transform V3 seasonal anime entry to V4 format.
     * Converts image_url → images{jpg, webp} structure with all required V4 fields.
     */
    private function transformSeasonAnimeToV4(array $item): array
    {
        // Remove V3 metadata fields
        unset($item['request_hash'], $item['request_cached'], $item['request_cache_expiry']);
        
        // Extract image URL and build V4 images object using existing method
        $imageUrl = $item['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);
        
        // Replace image_url with images object
        unset($item['image_url']);
        $item['images'] = $images;
        
        // Ensure other V4 required fields exist with proper defaults
        $item['trailer'] = $item['trailer'] ?? [
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
        ];
        
        $item['approved'] = $item['approved'] ?? true;
        $item['titles'] = $item['titles'] ?? [
            ['type' => 'Default', 'title' => $item['title'] ?? 'Unknown'],
        ];
        
        // Ensure airing field exists
        if (!isset($item['airing'])) {
            $item['airing'] = false;
        }
        
        // Convert airing_start to aired object if needed
        if (isset($item['airing_start']) && !isset($item['aired'])) {
            $item['aired'] = [
                'from' => $item['airing_start'],
                'to' => null,
                'prop' => [
                    'from' => null,
                    'to' => null,
                ],
                'string' => $item['airing_start'] ? date('M j, Y', strtotime($item['airing_start'])) : null,
            ];
        }
        
        return $item;
    }

    /**
     * Build V4-style images object from image URL.
     */
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

    // =========================================================================
    // RANDOM ENDPOINTS - QUALITY BASED
    // =========================================================================

    /**
     * Quality filter configuration for random anime.
     * Returns anime that users will actually enjoy watching!
     */
    private const QUALITY_CONFIG = [
        'min_score'           => 6.0,
        'max_score'           => 10.0,
        'min_popularity'      => 30000,
        'preferred_types'     => ['tv', 'movie', 'tv_short'],
        'accepted_types'      => ['tv', 'movie', 'tv_short', 'ova', 'ona', 'special', 'music'],
        'excluded_genres'     => [12, 49, 15], // Hentai, Erotica, Kids
        'accepted_status'     => ['Finished Airing', 'Currently Airing'],
    ];

    /**
     * GET /v4/random/anime - Returns HIGH-QUALITY random anime!
     * 
     * Quality Filters (built-in):
     * - Score: 6.0 - 10.0 (no bad anime)
     * - Popularity: 30,000+ members (well-known)
     * - Type: TV & Movies prioritized
     * - Auto-excludes: Hentai, Kids content
     * 
     * Query Params (optional):
     * - sfw: bool        - SFW mode (default: true)
     * - min_score: float - Min score (default: 6.0)
     * - type: string     - Preferred type: tv, movie, ova, etc.
     * - genre_id: int    - Filter by genre ID
     * 
     * @return \Illuminate\Http\Response
     */
    public function randomAnime(Request $request)
    {
        $sfw       = $this->parseSfw($request->get('sfw', true));
        $minScore  = (float) ($request->get('min_score', self::QUALITY_CONFIG['min_score']));
        $minPop    = $request->get('min_popularity') ? (int) $request->get('min_popularity') : self::QUALITY_CONFIG['min_popularity'];
        $prefType  = strtolower($request->get('type', ''));
        $genreFilter = $request->get('genre_id') ? (int) $request->get('genre_id') : null;
        
        $maxAttempts = 25;
        $attempt = 0;
        $candidates = [];
        $bestCandidate = null;
        $bestScore = 0;
        
        while ($attempt < $maxAttempts) {
            // Smart ID: 70% popular range (1-20000), 30% newer (20001-45000)
            $rand = mt_rand(1, 100);
            $randomId = ($rand <= 70) ? random_int(1, 20000) : random_int(20001, 45000);
            
            try {
                $anime = $this->jikan->getAnime(new \Jikan\Request\Anime\AnimeRequest($randomId));
                
                if (!$anime || empty($anime->getMalId())) {
                    $attempt++;
                    continue;
                }
                
                $data = json_decode($this->serializer->serialize($anime, 'json'), true);
                
                // === QUALITY FILTERS ===
                
                // 1. Score check
                $score = (float) ($data['score'] ?? 0);
                if ($score < $minScore || $score > self::QUALITY_CONFIG['max_score']) {
                    $attempt++;
                    continue;
                }
                
                // 2. Popularity check
                $members = (int) ($data['members'] ?? 0);
                if ($members < $minPop) {
                    $attempt++;
                    continue;
                }
                
                // 3. Type check
                $type = strtolower($data['type'] ?? '');
                if (!in_array($type, self::QUALITY_CONFIG['accepted_types'])) {
                    $attempt++;
                    continue;
                }
                
                // 4. Status check
                $status = $data['status'] ?? '';
                if (!in_array($status, self::QUALITY_CONFIG['accepted_status'])) {
                    $attempt++;
                    continue;
                }
                
                // 5. Genre exclusions (NSFW)
                $genreIds = array_column($data['genres'] ?? [], 'mal_id');
                if ($sfw && !empty(array_intersect($genreIds, self::QUALITY_CONFIG['excluded_genres']))) {
                    $attempt++;
                    continue;
                }
                
                // 6. Genre filter
                if ($genreFilter !== null && !in_array($genreFilter, $genreIds)) {
                    $attempt++;
                    continue;
                }
                
                // Calculate quality score
                $qualityScore = $this->calculateQualityScore($data, $prefType);
                
                $candidates[] = [
                    'data'         => $data,
                    'quality_score' => $qualityScore,
                    'score'        => $score,
                    'members'      => $members,
                    'type'         => $type,
                ];
                
                if ($qualityScore > $bestScore) {
                    $bestScore = $qualityScore;
                    $bestCandidate = $data;
                }
                
                // Found a great one!
                if ($qualityScore >= 85) {
                    break;
                }
                
            } catch (\Exception $e) {
                // Try another
            }
            
            $attempt++;
        }
        
        if (empty($candidates)) {
            $errorResponse = [
                'status'  => 404,
                'type'    => 'NotFound',
                'message' => 'No quality anime found after ' . $maxAttempts . ' attempts. Try lowering min_score.',
                'error'   => null,
            ];
            return response(json_encode($errorResponse, JSON_UNESCAPED_UNICODE))
                ->header('Content-Type', 'application/json')
                ->setStatusCode(404);
        }
        
        // Sort by quality and pick from top 5
        usort($candidates, function($a, $b) {
            return $b['quality_score'] - $a['quality_score'];
        });
        
        $topCandidates = array_slice($candidates, 0, min(5, count($candidates)));
        $selected = $topCandidates[array_rand($topCandidates)];
        $animeData = $selected['data'];
        
        // Transform to V4 format
        $v4Data = $this->transformRandomAnimeToV4($animeData);
        
        // Add quality metadata
        $v4Data['_quality_meta'] = [
            'score'   => $selected['score'],
            'members' => $selected['members'],
            'type'    => $selected['type'],
        ];
        
        return response(json_encode(['data' => $v4Data], JSON_UNESCAPED_UNICODE))
            ->header('Content-Type', 'application/json');
    }

    /**
     * Calculate quality score for weighted selection (0-100).
     * Higher = better anime.
     */
    private function calculateQualityScore(array $data, string $preferredType): float
    {
        $score = 0.0;
        
        // Score rating (max 40 points)
        $rating = (float) ($data['score'] ?? 0);
        $score += ($rating / 10.0) * 40;
        
        // Popularity (max 30 points)
        $members = (int) ($data['members'] ?? 0);
        if ($members > 500000) $score += 30;
        elseif ($members > 200000) $score += 25;
        elseif ($members > 100000) $score += 20;
        elseif ($members > 50000) $score += 15;
        else $score += 10;
        
        // Type preference (max 15 points)
        $type = strtolower($data['type'] ?? '');
        if (!empty($preferredType) && $type === $preferredType) {
            $score += 15;
        } elseif (in_array($type, ['tv', 'movie', 'tv_short'])) {
            $score += 12;
        } elseif (in_array($type, ['ova', 'ona'])) {
            $score += 8;
        } else {
            $score += 5;
        }
        
        // Episode count bonus (max 10 points)
        $episodes = (int) ($data['episodes'] ?? 0);
        if ($type === 'movie') {
            $score += 8;
        } elseif ($episodes >= 12 && $episodes <= 26) {
            $score += 10;  # Standard cour - ideal
        } elseif ($episodes >= 6 && $episodes < 12) {
            $score += 7;
        } elseif ($episodes > 26 && $episodes <= 52) {
            $score += 8;
        } elseif ($episodes > 52) {
            $score += 6;
        } else {
            $score += 3;
        }
        
        // Popular genres bonus (max 5 points)
        $genreIds = array_column($data['genres'] ?? [], 'mal_id');
        $popularGenres = [1, 2, 24, 10, 8]; // Action, Adventure, Sci-Fi, Fantasy, Drama
        $popularCount = count(array_intersect($genreIds, $popularGenres));
        $score += min($popularCount, 5);
        
        return min($score, 100);
    }

    /**
     * Transform random anime data to V4 format with full details.
     */
    private function transformRandomAnimeToV4(array $data): array
    {
        // Remove V3 metadata
        unset($data['request_hash'], $data['request_cached'], $data['request_cache_expiry']);
        
        // Build V4 images object
        $imageUrl = $data['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);
        unset($data['image_url']);
        $data['images'] = $images;
        
        // Build titles array in V4 format
        $titles = [['type' => 'Default', 'title' => $data['title'] ?? 'Unknown']];
        
        if (!empty($data['title_japanese'])) {
            $titles[] = ['type' => 'Japanese', 'title' => $data['title_japanese']];
        }
        if (!empty($data['title_english'])) {
            $titles[] = ['type' => 'English', 'title' => $data['title_english']];
        }
        // Add Romanji (main title is romaji in Jikan v3)
        if (!empty($data['title'])) {
            $titles[] = ['type' => 'Romanji', 'title' => $data['title']];
        }
        foreach ($data['title_synonyms'] ?? [] as $synonym) {
            $titles[] = ['type' => 'Synonym', 'title' => $synonym];
        }
        $data['titles'] = $titles;
        
        // Build trailer object
        $trailerUrl = $data['trailer_url'] ?? '';
        $data['trailer'] = [
            'youtube_id'      => null,
            'url'             => !empty($trailerUrl) ? $trailerUrl : null,
            'embed_url'       => !empty($trailerUrl) ? $trailerUrl : null,
            'images'          => [
                'image_url'        => null,
                'small_image_url'  => null,
                'medium_image_url' => null,
                'large_image_url'  => null,
                'maximum_image_url' => null,
            ],
        ];
        
        // Extract YouTube ID if present
        if (!empty($trailerUrl) && preg_match('#(?:youtube\.com/embed/|youtu\.be/)([a-zA-Z0-9_-]+)#', $trailerUrl, $m)) {
            $data['trailer']['youtube_id'] = $m[1];
        }
        unset($data['trailer_url']);
        
        // Ensure required V4 fields exist
        $data['approved'] = $data['approved'] ?? true;
        $data['airing'] = $data['airing'] ?? false;
        
        // Transform related → relations (V4 format)
        if (isset($data['related']) && is_array($data['related'])) {
            $firstItem = reset($data['related']);
            if (!isset($firstItem['relation']) && !isset($firstItem['items'])) {
                $relationsV4 = [];
                foreach ($data['related'] as $relationType => $items) {
                    if (is_array($items)) {
                        $relationsV4[] = [
                            'relation' => $relationType,
                            'items'    => $items,
                        ];
                    }
                }
                $data['relations'] = $relationsV4;
                unset($data['related']);
            }
        }
        
        return $data;
    }
}
