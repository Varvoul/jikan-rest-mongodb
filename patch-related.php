<?php
/**
 * Runtime patch for jikan-me/jikan AnimeParser::getRelated()
 *
 * MAL changed their HTML: the old class "anime_detail_related_anime" no longer exists.
 * New structure uses div.related-entries with:
 *   - div.entries-tile > div.entry (tile cards with div.relation + div.title > a)
 *   - table.entries-table > tr (table rows with td.ar + td > ul.entries > li > a)
 *
 * This script patches the getRelated() method in the installed vendor file.
 */

$parserFile = '/app/vendor/jikan-me/jikan/src/Parser/Anime/AnimeParser.php';

if (!file_exists($parserFile)) {
    echo "[patch-related] AnimeParser.php not found at $parserFile\n";
    exit(1);
}

$content = file_get_contents($parserFile);

// The new getRelated() method - handles new MAL HTML + fallback to old
$newMethod = <<<'METHOD'
    public function getRelated(): array
    {
        $related = [];

        // === STRATEGY 1: NEW MAL tile format ===
        // div.related-entries > div.entries-tile > div.entry
        //   div.content > div.relation  -> "Sequel (TV)"
        //   div.content > div.title > a -> link
        $this->crawler
            ->filter('div.related-entries div.entries-tile div.entry')
            ->each(
                function (Crawler $entry) use (&$related) {
                    $relationNode = $entry->filter('div.content > div.relation');
                    if (!$relationNode->count()) {
                        return;
                    }

                    // Get raw text like "Sequel\n                  (TV)" and clean it
                    $rawRelation = $relationNode->text();
                    // Remove parenthetical type hints like "(TV)", "(Manga)", "(Special)"
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
        // table.entries-table > tr
        //   td.ar > "Side Story:"
        //   td > ul.entries > li > a > links
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

// Find the getRelated() method using brace counting
$methodStart = strpos($content, 'public function getRelated()');
if ($methodStart === false) {
    $methodStart = strpos($content, 'public function getRelated ()');
}
if ($methodStart === false) {
    echo "[patch-related] Could not locate getRelated method\n";
    exit(1);
}

// Find the opening brace
$braceStart = strpos($content, '{', $methodStart);
if ($braceStart === false) {
    echo "[patch-related] Could not find opening brace\n";
    exit(1);
}

// Count braces to find the matching closing brace, skipping strings
$depth = 0;
$pos = $braceStart;
$methodEnd = false;
$len = strlen($content);
while ($pos < $len) {
    $ch = $content[$pos];
    if ($ch === '{') {
        $depth++;
    } elseif ($ch === '}') {
        $depth--;
        if ($depth === 0) {
            $methodEnd = $pos + 1;
            break;
        }
    } elseif ($ch === "'" || $ch === '"') {
        // Skip string contents to avoid counting braces inside strings
        $quote = $ch;
        $pos++;
        while ($pos < $len && $content[$pos] !== $quote) {
            if ($content[$pos] === '\\') {
                $pos++; // skip escaped character
            }
            $pos++;
        }
    }
    $pos++;
}

if ($methodEnd === false) {
    echo "[patch-related] Could not find matching closing brace\n";
    exit(1);
}

// Replace the method
$newContent = substr($content, 0, $methodStart) . $newMethod . substr($content, $methodEnd);

if ($newContent === $content) {
    echo "[patch-related] No changes made (content identical)\n";
    exit(1);
}

file_put_contents($parserFile, $newContent);
echo "[patch-related] Successfully patched AnimeParser::getRelated() for new MAL HTML structure\n";
exit(0);