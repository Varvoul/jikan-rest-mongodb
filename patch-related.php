<?php
/**
 * Runtime patch for jikan-me/jikan AnimeParser::getRelated()
 *
 * MAL changed their HTML: the old class "anime_detail_related_anime" no longer exists.
 * New structure uses div.related-entries with:
 *   - div.entries-tile > div.entry (tile cards with div.relation + div.title > a)
 *   - table.entries-table > tr (table rows with td.ar + td > ul.entries > li > a)
 */

$parserFile = '/app/vendor/jikan-me/jikan/src/Parser/Anime/AnimeParser.php';

if (!file_exists($parserFile)) {
    echo "[patch-related] AnimeParser.php not found\n";
    exit(1);
}

$content = file_get_contents($parserFile);
$originalLength = strlen($content);

// The new getRelated() method
$newMethod = <<<'METHOD'
    public function getRelated(): array
    {
        $related = [];

        // === STRATEGY 1: NEW MAL tile format ===
        $this->crawler
            ->filter('div.related-entries div.entries-tile div.entry')
            ->each(
                function (Crawler $entry) use (&$related) {
                    $relationNode = $entry->filter('div.content > div.relation');
                    if (!$relationNode->count()) {
                        return;
                    }

                    $rawRelation = $relationNode->text();
                    $relation = preg_replace('/\s*\([^)]+\)\s*/', '', $rawRelation);
                    $relation = str_replace(':', '', $relation);
                    $relation = JString::cleanse($relation);

                    if (empty($relation)) {
                        return;
                    }

                    $linkNode = $entry->filter('div.content > div.title > a');
                    if (!$linkNode->count()) {
                        return;
                    }

                    $malUrl = (new MalUrlParser($linkNode))->getModel();
                    if (!isset($related[$relation])) {
                        $related[$relation] = [];
                    }
                    $related[$relation][] = $malUrl;
                }
            );

        if (!empty($related)) {
            return $related;
        }

        // === STRATEGY 2: NEW MAL table format ===
        $this->crawler
            ->filter('div.related-entries table.entries-table tr')
            ->each(
                function (Crawler $c) use (&$related) {
                    $relationNode = $c->filter('td.ar');
                    if (!$relationNode->count()) {
                        $relationNode = $c->filter('td')->first();
                    }
                    if (!$relationNode->count()) {
                        return;
                    }

                    $relation = JString::cleanse(
                        str_replace(':', '', $relationNode->text())
                    );

                    if (empty($relation)) {
                        return;
                    }

                    $links = $c->filter('td')->last()->filter('a');
                    if (!$links->count()) {
                        $links = $c->filter('a');
                    }

                    if ($links->count() == 1
                        && empty($links->first()->text())
                    ) {
                        $related[$relation] = [];
                        return;
                    }

                    foreach ($links as $node) {
                        if (empty($node->textContent)) {
                            $node->parentNode->removeChild($node);
                        }
                    }

                    $related[$relation] = $links->each(function (Crawler $c) {
                        return (new MalUrlParser($c))->getModel();
                    });
                }
            );

        if (!empty($related)) {
            return $related;
        }

        // === STRATEGY 3: OLD MAL format (fallback) ===
        $this->crawler
            ->filterXPath('//table[contains(@class, "anime_detail_related_anime")]/tr')
            ->each(
                function (Crawler $c) use (&$related) {
                    $links = $c->filterXPath('//td[2]/a');
                    $relation = JString::cleanse(
                        str_replace(':', '', $c->filterXPath('//td[1]')->text())
                    );

                    if ($links->count() == 1
                        && empty($links->first()->text())
                    ) {
                        $related[$relation] = [];
                        return;
                    }

                    foreach ($links as $node) {
                        if (empty($node->textContent)) {
                            $node->parentNode->removeChild($node);
                        }
                    }

                    $related[$relation] = $links->each(function (Crawler $c) {
                        return (new MalUrlParser($c))->getModel();
                    });
                }
            );

        return $related;
    }
METHOD;

// Use regex with 's' flag (DOTALL) to match the entire method
// Match: from "public function getRelated(): array" up to "return $related;\n    }"
$pattern = '/public\s+function\s+getRelated\s*\(\s*\)\s*:\s*array\s*\{.*?return\s+\$related\s*;\s*\}/s';

if (!preg_match($pattern, $content)) {
    echo "[patch-related] ERROR: regex did not match getRelated() method\n";
    // Debug: show what's around the method
    $pos = strpos($content, 'function getRelated');
    if ($pos !== false) {
        echo "[patch-related] Found 'function getRelated' at pos $pos\n";
        echo "[patch-related] Context: " . json_encode(substr($content, $pos, 80)) . "\n";
        // Check for \r\n
        if (strpos($content, "\r\n") !== false) {
            echo "[patch-related] NOTE: file uses CRLF line endings\n";
        }
    }
    exit(1);
}

$newContent = preg_replace($pattern, $newMethod, $content, 1);

if ($newContent === null || $newContent === $content) {
    echo "[patch-related] ERROR: preg_replace failed or no change\n";
    exit(1);
}

file_put_contents($parserFile, $newContent);
echo "[patch-related] Successfully patched AnimeParser::getRelated() (replaced " . strlen($content) . " -> " . strlen($newContent) . " bytes)\n";
exit(0);