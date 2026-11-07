<?php

/*
 * PATCHED-T153 (Varvoul/jikan-rest-mongodb): null-safe promo item parser for
 * MAL's redesigned /anime/{id}/_/video page (see StreamEpisodeListItemParser
 * in this directory for the full rationale).
 *
 * The promo selectors still matched MAL's current markup, but they were
 * one class rename away from the same 500 — this patch adds the same
 * count() guards and '' fallbacks so a partial promo item can never take
 * down the whole /v4/anime/{id}/videos response.
 *
 * Applied by the Dockerfile vendor bake and mirrored at boot by
 * patch-videos.php (docker-entrypoint.sh).
 */

namespace Jikan\Parser\Anime;

use Jikan\Model\Anime\PromoListItem;
use Jikan\Parser\ParserInterface;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Class PromoListItemParser
 *
 * @package Jikan\Parser\Episode
 */
class PromoListItemParser implements ParserInterface
{
    /**
     * @var Crawler
     */
    private $crawler;

    /**
     * PromoItemParser constructor.
     *
     * @param Crawler $crawler
     */
    public function __construct(Crawler $crawler)
    {
        $this->crawler = $crawler;
    }

    /**
     * @return PromoListItem
     */
    public function getModel(): PromoListItem
    {
        return PromoListItem::fromParser($this);
    }

    /**
     * @return string
     */
    public function getTitle(): string
    {
        try {
            $node = $this->crawler->filterXPath('//a/div/span');
            if ($node->count()) {
                return $node->text();
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

    /**
     * @return string
     */
    public function getVideoUrl(): string
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
}
