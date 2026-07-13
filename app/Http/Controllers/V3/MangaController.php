<?php

namespace App\Http\Controllers\V3;

use Jikan\Request\Manga\MangaCharactersRequest;
use Jikan\Request\Manga\MangaForumRequest;
use Jikan\Request\Manga\MangaMoreInfoRequest;
use Jikan\Request\Manga\MangaNewsRequest;
use Jikan\Request\Manga\MangaPicturesRequest;
use Jikan\Request\Manga\MangaRecentlyUpdatedByUsersRequest;
use Jikan\Request\Manga\MangaRecommendationsRequest;
use Jikan\Request\Manga\MangaRequest;
use Jikan\Request\Manga\MangaReviewsRequest;
use Jikan\Request\Manga\MangaStatsRequest;

class MangaController extends Controller
{
    public function main(int $id)
    {
        $manga = $this->jikan->getManga(new MangaRequest($id));
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function full(int $id)
    {
        // 1. Main manga data
        $mainManga = $this->jikan->getManga(new MangaRequest($id));
        $mainData = json_decode($this->serializer->serialize($mainManga, 'json'), true);

        // 2. Pictures
        $picturesResult = $this->jikan->getMangaPictures(new MangaPicturesRequest($id));
        $picturesData = json_decode($this->serializer->serialize(['pictures' => $picturesResult], 'json'), true);

        // 3. Characters (includes voice actors for animeography)
        $charactersResult = $this->jikan->getMangaCharacters(new MangaCharactersRequest($id));
        $charactersData = json_decode($this->serializer->serialize(['characters' => $charactersResult], 'json'), true);

        // 4. Statistics
        $statsResult = $this->jikan->getMangaStats(new MangaStatsRequest($id));
        $statsData = json_decode($this->serializer->serialize($statsResult, 'json'), true);

        // Combine all data
        $combined = $mainData;

        // Add pictures
        if (isset($picturesData['pictures'])) {
            $combined['pictures'] = $picturesData['pictures'];
        }

        // Add characters
        if (isset($charactersData['characters'])) {
            $combined['characters'] = $charactersData['characters'];
        }

        // Add statistics
        foreach ($statsData as $key => $value) {
            if (!isset($combined[$key])) {
                $combined[$key] = $value;
            }
        }

        return response(json_encode($combined));
    }

    public function characters(int $id)
    {
        $manga = ['characters' => $this->jikan->getMangaCharacters(new MangaCharactersRequest($id))];
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function news(int $id)
    {
        $manga = ['articles' => $this->jikan->getNewsList(new MangaNewsRequest($id))];
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function forum(int $id, ?string $topic = null)
    {
        // safely bypass MAL's naming schemes
        if ($topic === 'chapters') {
            $topic = 'episode';
        }

        $manga = ['topics' => $this->jikan->getMangaForum(new MangaForumRequest($id, $topic))];
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function pictures(int $id)
    {
        $manga = ['pictures' => $this->jikan->getMangaPictures(new MangaPicturesRequest($id))];
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function stats(int $id)
    {
        $manga = $this->jikan->getMangaStats(new MangaStatsRequest($id));
        return response($this->serializer->serialize($manga, 'json'));
    }

    public function moreInfo(int $id)
    {
        $manga = ['moreinfo' => $this->jikan->getMangaMoreInfo(new MangaMoreInfoRequest($id))];
        return response(json_encode($manga));
    }

    public function recommendations(int $id)
    {
        $anime = ['recommendations' => $this->jikan->getMangaRecommendations(new MangaRecommendationsRequest($id))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function userupdates(int $id, int $page = 1)
    {
        $anime = ['users' => $this->jikan->getMangaRecentlyUpdatedByUsers(new MangaRecentlyUpdatedByUsersRequest($id, $page))];
        return response($this->serializer->serialize($anime, 'json'));
    }

    public function reviews(int $id, int $page = 1)
    {
        $manga = ['reviews' => $this->jikan->getMangaReviews(new MangaReviewsRequest($id, $page))];
        return response($this->serializer->serialize($manga, 'json'));
    }
}