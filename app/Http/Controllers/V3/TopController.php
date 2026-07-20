<?php

namespace App\Http\Controllers\V3;

use Jikan\Request\Top\TopAnimeRequest;
use Jikan\Request\Top\TopMangaRequest;
use Jikan\Request\Top\TopCharactersRequest;
use Jikan\Request\Top\TopPeopleRequest;
use Jikan\Helper\Constants as JikanConstants;

class TopController extends Controller
{
    /**
     * Default items per page for top lists
     */
    private const PER_PAGE = 50;

    /**
     * Estimated total counts for pagination (MAL top lists are typically 500 items)
     */
    private const ANIME_TOTAL = 500;
    private const MANGA_TOTAL = 500;
    private const CHARACTERS_TOTAL = 100;
    private const PEOPLE_TOTAL = 100;

    // ═══════════════════════════════════════════════════════════════════
    //  TOP ANIME
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /top/anime[/{page}[/{type}]]
     *
     * Types: airing, upcoming, tv, movie, ova, special, by_popularity, favorites
     */
    public function anime(int $page = 1, string $type = null)
    {
        if (!is_null($type) && !\in_array(strtolower($type), [
                JikanConstants::TOP_AIRING,
                JikanConstants::TOP_UPCOMING,
                JikanConstants::TOP_TV,
                JikanConstants::TOP_MOVIE,
                JikanConstants::TOP_OVA,
                JikanConstants::TOP_SPECIAL,
                JikanConstants::TOP_BY_POPULARITY,
                JikanConstants::TOP_BY_FAVORITES,
            ])) {
            return response()->json([
                'error' => 'Invalid type. Valid types: airing, upcoming, tv, movie, ova, special, by_popularity, favorites'
            ])->setStatusCode(400);
        }

        $page = max(1, $page);

        try {
            $response = $this->jikan->getTopAnime(new TopAnimeRequest($page, $type));
            $raw = json_decode($this->serializer->serialize(['top' => $response], 'json'), true);
            $items = $raw['top'] ?? [];
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch top anime: ' . $e->getMessage()
            ])->setStatusCode(500);
        }

        // Transform each anime entry to V4 format
        $data = [];
        foreach ($items as $item) {
            $data[] = $this->transformAnimeToV4($item);
        }

        // Calculate pagination
        $lastPage = max(1, (int) ceil(self::ANIME_TOTAL / self::PER_PAGE));
        $hasNext = $page < $lastPage;

        return $this->buildV4Response($data, $page, $lastPage, self::ANIME_TOTAL, self::PER_PAGE, $hasNext);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TOP MANGA
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /top/manga[/{page}[/{type}]]
     *
     * Types: manga, novel, oneshot, doujinshi, manhwa, manhua, by_popularity, favorites
     */
    public function manga(int $page = 1, string $type = null)
    {
        if (!is_null($type) && !\in_array(
            strtolower($type),
            [
                JikanConstants::TOP_MANGA,
                JikanConstants::TOP_NOVEL,
                JikanConstants::TOP_ONE_SHOT,
                JikanConstants::TOP_DOUJINSHI,
                JikanConstants::TOP_MANHWA,
                JikanConstants::TOP_MANHUA,
                JikanConstants::TOP_BY_POPULARITY,
                JikanConstants::TOP_BY_FAVORITES,
                ]
            )) {
            return response()->json([
                'error' => 'Invalid type. Valid types: manga, novel, oneshot, doujinshi, manhwa, manhua, by_popularity, favorites'
            ])->setStatusCode(400);
        }

        $page = max(1, $page);

        try {
            $response = $this->jikan->getTopManga(new TopMangaRequest($page, $type));
            $raw = json_decode($this->serializer->serialize(['top' => $response], 'json'), true);
            $items = $raw['top'] ?? [];
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch top manga: ' . $e->getMessage()
            ])->setStatusCode(500);
        }

        // Transform each manga entry to V4 format
        $data = [];
        foreach ($items as $item) {
            $data[] = $this->transformMangaToV4($item);
        }

        // Calculate pagination
        $lastPage = max(1, (int) ceil(self::MANGA_TOTAL / self::PER_PAGE));
        $hasNext = $page < $lastPage;

        return $this->buildV4Response($data, $page, $lastPage, self::MANGA_TOTAL, self::PER_PAGE, $hasNext);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TOP PEOPLE
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /top/people[/{page}]
     */
    public function people(int $page = 1)
    {
        $page = max(1, $page);

        try {
            $response = $this->jikan->getTopPeople(new TopPeopleRequest($page));
            $raw = json_decode($this->serializer->serialize(['top' => $response], 'json'), true);
            $items = $raw['top'] ?? [];
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch top people: ' . $e->getMessage()
            ])->setStatusCode(500);
        }

        // Transform each person entry to V4 format
        $data = [];
        foreach ($items as $item) {
            $data[] = $this->transformPersonToV4($item);
        }

        // Calculate pagination
        $lastPage = max(1, (int) ceil(self::PEOPLE_TOTAL / self::PER_PAGE));
        $hasNext = $page < $lastPage;

        return $this->buildV4Response($data, $page, $lastPage, self::PEOPLE_TOTAL, self::PER_PAGE, $hasNext);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  TOP CHARACTERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * GET /top/characters[/{page}]
     */
    public function characters(int $page = 1)
    {
        $page = max(1, $page);

        try {
            $response = $this->jikan->getTopCharacters(new TopCharactersRequest($page));
            $raw = json_decode($this->serializer->serialize(['top' => $response], 'json'), true);
            $items = $raw['top'] ?? [];
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to fetch top characters: ' . $e->getMessage()
            ])->setStatusCode(500);
        }

        // Transform each character entry to V4 format
        $data = [];
        foreach ($items as $item) {
            $data[] = $this->transformCharacterToV4($item);
        }

        // Calculate pagination
        $lastPage = max(1, (int) ceil(self::CHARACTERS_TOTAL / self::PER_PAGE));
        $hasNext = $page < $lastPage;

        return $this->buildV4Response($data, $page, $lastPage, self::CHARACTERS_TOTAL, self::PER_PAGE, $hasNext);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V4 RESPONSE BUILDER
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Build a v4-style paginated JSON response.
     */
    private function buildV4Response(
        array $data,
        int   $page,
        int   $lastPage,
        int   $total,
        int   $perPage,
        bool  $hasNext
    ) {
        return response(json_encode([
            'pagination' => [
                'last_visible_page' => $lastPage,
                'has_next_page'     => $hasNext,
                'current_page'      => $page,
                'items'             => [
                    'count'    => count($data),
                    'total'    => $total,
                    'per_page' => $perPage,
                ],
            ],
            'data' => $data,
        ]));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  V3 → V4 TRANSFORM METHODS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Transform V3 top anime item to V4 format with all required fields.
     */
    private function transformAnimeToV4(array $a): array
    {
        // Strip V3 metadata
        unset($a['request_hash'], $a['request_cached'], $a['request_cache_expiry']);

        // Build images object
        $imageUrl = $a['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);

        // Build titles array
        $titles = [
            ['type' => 'Default', 'title' => $a['title'] ?? ''],
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

        // Build broadcast object
        $broadcast = null;
        if (isset($a['broadcast'])) {
            if (is_array($a['broadcast'])) {
                $broadcast = $a['broadcast'];
            } elseif (is_string($a['broadcast']) && $a['broadcast'] !== '') {
                $broadcast = $this->parseBroadcast($a['broadcast']);
            }
        }

        // Season + Year extraction
        $season = null;
        $year = null;
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
                $year = (int) $pm[2];
            }
        }

        // Build V4 anime object
        return [
            'mal_id'          => $a['mal_id'] ?? null,
            'url'             => $a['url'] ?? '',
            'images'          => $images,
            'trailer'         => [
                'youtube_id'      => $a['trailer_url'] ?? ($a['trailer']['youtube_id'] ?? null),
                'url'             => $a['trailer_url'] ?? null,
                'embed_url'       => null,
                'images'          => [
                    'image_url'        => null,
                    'small_image_url'  => null,
                    'medium_image_url' => null,
                    'large_image_url'  => null,
                    'maximum_image_url'=> null,
                ],
            ],
            'approved'        => $a['approved'] ?? true,
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
            'score'           => isset($a['score']) ? (float) $a['score'] : null,
            'scored_by'       => isset($a['scored_by']) ? (int) $a['scored_by'] : null,
            'rank'            => isset($a['rank']) ? (int) $a['rank'] : null,
            'popularity'      => isset($a['popularity']) ? (int) $a['popularity'] : null,
            'members'         => isset($a['members']) ? (int) $a['members'] : null,
            'favorites'       => isset($a['favorites']) ? (int) $a['favorites'] : null,
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
    }

    /**
     * Transform V3 top manga item to V4 format with all required fields.
     */
    private function transformMangaToV4(array $m): array
    {
        // Strip V3 metadata
        unset($m['request_hash'], $m['request_cached'], $m['request_cache_expiry']);

        // Build images object
        $imageUrl = $m['image_url'] ?? '';
        $images = $this->buildImagesObject($imageUrl);

        // Build titles array
        $titles = [
            ['type' => 'Default', 'title' => $m['title'] ?? ''],
        ];
        if (!empty($m['title_japanese'])) {
            $titles[] = ['type' => 'Japanese', 'title' => $m['title_japanese']];
        }
        if (!empty($m['title_english'])) {
            $titles[] = ['type' => 'English', 'title' => $m['title_english']];
        }
        foreach ($m['title_synonyms'] ?? [] as $syn) {
            $titles[] = ['type' => 'Synonym', 'title' => $syn];
        }

        // Build published object from string
        $published = null;
        if (isset($m['published'])) {
            if (is_array($m['published'])) {
                $published = $m['published'];
            } elseif (is_string($m['published']) && $m['published'] !== '') {
                $published = $this->parsePublished($m['published']);
            }
        }

        return [
            'mal_id'          => $m['mal_id'] ?? null,
            'url'             => $m['url'] ?? '',
            'images'          => $images,
            'approved'        => $m['approved'] ?? true,
            'titles'          => $titles,
            'title'           => $m['title'] ?? '',
            'title_english'   => $m['title_english'] ?? null,
            'title_japanese'  => $m['title_japanese'] ?? null,
            'title_synonyms'  => $m['title_synonyms'] ?? [],
            'type'            => $m['type'] ?? null,
            'chapters'        => isset($m['chapters']) ? (int) $m['chapters'] : null,
            'volumes'         => isset($m['volumes']) ? (int) $m['volumes'] : null,
            'status'          => $m['status'] ?? null,
            'publishing'      => $m['publishing'] ?? false,
            'published'       => $published,
            'score'           => isset($m['score']) ? (float) $m['score'] : null,
            'scored_by'       => isset($m['scored_by']) ? (int) $m['scored_by'] : null,
            'rank'            => isset($m['rank']) ? (int) $m['rank'] : null,
            'popularity'      => isset($m['popularity']) ? (int) $m['popularity'] : null,
            'members'         => isset($m['members']) ? (int) $m['members'] : null,
            'favorites'       => isset($m['favorites']) ? (int) $m['favorites'] : null,
            'synopsis'        => $m['synopsis'] ?? null,
            'background'      => $m['background'] ?? null,
            'authors'         => $m['authors'] ?? [],
            'serializations'  => $m['serializations'] ?? [],
            'genres'          => $m['genres'] ?? [],
            'explicit_genres' => $m['explicit_genres'] ?? [],
            'themes'          => $m['themes'] ?? [],
            'demographics'    => $m['demographics'] ?? [],
        ];
    }

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

        // Build anime/manga appearances with proper V4 structure
        $animeAppearances = [];
        foreach ($c['anime'] ?? [] as $anime) {
            $animeAppearances[] = [
                'role'       => $anime['role'] ?? '',
                'mal_id'     => $anime['mal_id'] ?? null,
                'url'        => $anime['url'] ?? '',
                'images'     => $this->buildImagesObject($anime['image_url'] ?? ''),
                'name'       => $anime['name'] ?? '',
            ];
        }

        $mangaAppearances = [];
        foreach ($c['manga'] ?? [] as $manga) {
            $mangaAppearances[] = [
                'role'       => $manga['role'] ?? '',
                'mal_id'     => $manga['mal_id'] ?? null,
                'url'        => $manga['url'] ?? '',
                'images'     => $this->buildImagesObject($manga['image_url'] ?? ''),
                'name'       => $manga['name'] ?? '',
            ];
        }

        // Build nicknames array
        $nicknames = $c['nickname_japanese'] ?? [];
        if (!is_array($nicknames)) {
            $nicknames = [$nicknames];
        }
        if (empty($nicknames) && !empty($c['nicknames'])) {
            $nicknames = is_array($c['nicknames']) ? $c['nicknames'] : [$c['nicknames']];
        }

        return [
            'mal_id'        => $c['mal_id'] ?? null,
            'url'           => $c['url'] ?? '',
            'images'        => $images,
            'name'          => $c['name'] ?? '',
            'name_kanji'    => $c['name_kanji'] ?? null,
            'nicknames'     => $nicknames,
            'favorites'     => isset($c['favorites']) ? (int) $c['favorites'] : null,
            'about'         => $c['about'] ?? null,
            'anime'         => $animeAppearances,
            'manga'         => $mangaAppearances,
            'voices'        => [], // Not available in top list endpoint
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
                'position' => $position['staff'] ?? '',
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
                'role'     => $role['role'] ?? '',
                'mal_id'   => $role['mal_id'] ?? null,
                'url'      => $role['url'] ?? '',
                'images'   => $this->buildImagesObject($role['image_url'] ?? ''),
                'name'     => $role['name'] ?? '',
            ];
        }

        // Build author positions for manga
        $authorPositions = [];
        foreach ($p['author_positions'] ?? [] as $position) {
            $authorPositions[] = [
                'position' => $position['position'] ?? '',
                'mal_id'   => $position['mal_id'] ?? null,
                'url'      => $position['url'] ?? '',
                'images'   => $this->buildImagesObject($position['image_url'] ?? ''),
                'name'     => $position['name'] ?? '',
            ];
        }

        return [
            'mal_id'              => $p['mal_id'] ?? null,
            'url'                 => $p['url'] ?? '',
            'images'              => $images,
            'given_name'          => $p['given_name'] ?? null,
            'family_name'         => $p['family_name'] ?? null,
            'alternative_names'   => $alternativeNames ?: ($p['alternate_name'] ?? []),
            'birthday'            => $p['birthday'] ?? null,
            'favorites'           => isset($p['favorites']) ? (int) $p['favorites'] : null,
            'about'               => $p['about'] ?? null,
            'data'                => [
                'about'             => $p['about'] ?? null,
                'alternate_names'   => $alternativeNames ?: ($p['alternate_name'] ?? []),
                    'voice_acting_roles' => $voiceActingRoles,
                    'anime_staff_positions' => $animeStaffPositions,
                    'author_positions' => $authorPositions,
                ],
            'anime_staff_positions' => $animeStaffPositions,
            'voice_acting_roles'    => $voiceActingRoles,
            'author_positions'      => $authorPositions,
        ];
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPER METHODS
    // ═══════════════════════════════════════════════════════════════════

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

    /**
     * Parse broadcast string into V4 broadcast object.
     */
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

    /**
     * Parse published string into V4 published object.
     */
    private function parsePublished(string $str): array
    {
        $obj = ['string' => $str, 'from' => null, 'to' => null];

        // Match patterns like "Jan 5, 2012 to ?", "2009 - 2012", "2010"
        if (preg_match('/(\w+\s+\d{1,2},?\s*\d{4})\s*(?:to|-)\s*(\?|\w+\s+\d{1,2},?\s*\d{4}|N\/A|\d{4})?/i', $str, $m)) {
            $obj['from'] = $m[1];
            $obj['to'] = (isset($m[2]) && $m[2] !== '?' && $m[2] !== 'N/A') ? $m[2] : null;
        } elseif (preg_match('/^(\d{4})\s*(?:-|to)\s*(\d{4})?$/', trim($str), $m)) {
            $obj['from'] = $m[1] . '-01-01T00:00:00+00:00';
            $obj['to'] = isset($m[2]) ? $m[2] . '-12-31T23:59:59+00:00' : null;
        } elseif (preg_match('/^(\d{4})$/', trim($str), $m)) {
            $obj['from'] = $m[1] . '-01-01T00:00:00+00:00';
        }

        return $obj;
    }
}
