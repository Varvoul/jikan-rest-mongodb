<?php

/*
 * PATCHED-T153 (Varvoul/jikan-rest-mongodb): null-safe video item parser for
 * MAL's redesigned /anime/{id}/_/video page.
 *
 * MAL changed the episode label span from <span class="title"> to
 * <span class="title js-color-pc-constant">, so the original exact-class XPath
 * `span[@class="title"]` matched nothing and Crawler::text() threw
 * InvalidArgumentException, 500-ing every /v4/anime/{id}/videos request with
 * "Failed to parse '.../video'".
 *
 * Changes vs jikan-me/jikan 2.19.4:
 *   - class-word matching (concat/normalize-space idiom) instead of exact class
 *   - count() guards with '' fallbacks — never throws on missing nodes
 *   - image URL falls back from data-src to src
 *
 * Applied by the Dockerfile vendor bake and mirrored at boot by
 * patch-videos.php (docker-entrypoint.sh).
 */

namespace Jikan\Parser\Anime;

use Jikan\Helper\Parser;
use Jikan\Model\Anime\StreamEpisodeListItem;
use Jikan\Parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class StreamEpisodeListItemParser
 *
 * @package Jikan\Parser\Episode
 */
class StreamEpisodeListItemParser implements ParserInterface
{
    /**
     * @var Crawler
     */
    private $crawler;

    /**
     * EpisodeListItemParser constructor.
     *
     * @param Crawler $crawler
     */
    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * @return StreamEpisodeListItem
     */
    public function getModel(): StreamEpisodeListItem
    {
        return StreamEpisodeListItem::fromParser($this);
    }

    /**
     * Episode page title, e.g. "Asteroid Blues". Empty string when absent.
     *
     * @return string
     */
    public function getTitle(): string
    {
        try {
            $node = $this->crawler->filterXPath(
                '//a/div/span/span[contains(concat(\' \', normalize-space(@class), \' \'), \' episode-title \')]'
            );
            if ($node->count()) {
                return $node->text();
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    /**
     * Episode label, e.g. "Episode 1". Empty string when absent.
     *
     * @return string
     */
    public function getEpisode(): string
    {
        try {
            $node = $this->crawler->filterXPath(
                '//a/div/span[contains(concat(\' \', normalize-space(@class), \' \'), \' title \')]'
            );
            if ($node->count()) {
                return Parser::removeChildNodes($node)->text();
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        try {
            $node = $this->crawler->filterXPath('//a');
            if ($node->count()) {
                return (string) $node->attr('href');
            }
        } catch (\Exception $e) {
        }

        return '';
    }

    /**
     * @return string
     */
    public function getImageUrl(): string
    {
        try {
            $img = $this->crawler->filterXPath('//a/img');
            if (!$img->count()) {
                return '';
            }

            try {
                $dataSrc = (string) $img->attr('data-src');
                if ('' !== $dataSrc) {
                    return $dataSrc;
                }
            } catch (\Exception $e) {
                // data-src attribute missing — fall through to src
            }

            try {
                return (string) $img->attr('src');
            } catch (\Exception $e) {
                // src attribute missing too
            }
        } catch (\Exception $e) {
        }

        return '';
    }
}
