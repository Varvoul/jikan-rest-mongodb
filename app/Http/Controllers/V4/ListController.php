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

        if ($sfw) {
            $results = $this->filterSfw($results);
        }

        return response($this->buildV4Response($results, $page, $limit, $lastPage, $total));
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

        return [
            'mal_id'     => $c['mal_id'] ?? null,
            'url'        => $c['url'] ?? '',
            'images'     => $images,
            'name'       => $c['title'] ?? $c['name'] ?? '',
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
}
