<?php
/**
 * Runtime patch for jikan-me/jikan AnimeParser::getExternalLinks()
 *
 * MAL changed the External Links section. Old structure had a single
 * <h2>External Links</h2> heading. New structure has separate headings
 * like "Available At", "Resources" with div.external_links containers.
 */

$parserFile = '/app/vendor/jikan-me/jikan/src/Parser/Anime/AnimeParser.php';

if (!file_exists($parserFile)) {
    echo "[patch-external] AnimeParser.php not found\n";
    exit(1);
}

$content = file_get_contents($parserFile);

$newMethod = <<<'METHOD'
    public function getExternalLinks(): array
    {
        $links = [];

        // === STRATEGY 1: NEW MAL format ===
        // <h2>Available At</h2><div class="external_links"><a ...>...</a></div>
        // <h2>Resources</h2><div class="external_links"><a ...>...</a></div>
        $this->crawler
            ->filter('div.external_links a')
            ->each(function (Crawler $c) use (&$links) {
                $href = $c->attr('href');
                $text = trim($c->text());
                // Skip empty or bookstore links
                if (empty($href) || empty($text)) {
                    return;
                }
                if (strpos($href, 'mangamirai.com') !== false) {
                    return;
                }
                $name = $text;
                $url = $href;
                // Extract MAL ID from URL if it's a MAL link
                $malId = null;
                $type = 'external';
                if (preg_match('#myanimelist\.net/(anime|manga)/(\d+)#', $url, $m)) {
                    $malId = (int) $m[2];
                    $type = $m[1];
                }
                $links[] = [
                    'name' => $name,
                    'url' => $url,
                    'mal_id' => $malId,
                    'type' => $type,
                ];
            });

        if (!empty($links)) {
            return $links;
        }

        // === STRATEGY 2: OLD MAL format (fallback) ===
        $oldLinks = $this->crawler
            ->filterXPath('//*[@id="content"]/table/tr/td[1]/div/h2[contains(text(), "External Links")]');

        if ($oldLinks->count()) {
            return $oldLinks->nextAll()->filterXPath('//div[contains(@class, "pb16")]/a')
                ->each(function (Crawler $c) {
                    return (new UrlParser($c))->getModel();
                });
        }

        return [];
    }
METHOD;

// Use regex to match and replace the getExternalLinks method
$pattern = '/public\s+function\s+getExternalLinks\s*\(\s*\)\s*:\s*array\s*\{.*?return\s+\[\];\s*\}/s';

// More specific pattern since "return [];" appears multiple times
$pattern = '/public\s+function\s+getExternalLinks\s*\(\s*\)\s*:\s*array\s*\{.*?\n        return \[\];\s*\n    \}/s';

if (!preg_match($pattern, $content)) {
    echo "[patch-external] ERROR: regex did not match getExternalLinks()\n";
    $pos = strpos($content, 'function getExternalLinks');
    if ($pos !== false) {
        echo "[patch-external] Found at pos $pos: " . json_encode(substr($content, $pos, 80)) . "\n";
    }
    exit(1);
}

$newContent = preg_replace($pattern, $newMethod, $content, 1);

if ($newContent === null || $newContent === $content) {
    echo "[patch-external] ERROR: preg_replace failed\n";
    exit(1);
}

file_put_contents($parserFile, $newContent);
echo "[patch-external] Successfully patched (" . strlen($content) . " -> " . strlen($newContent) . " bytes)\n";
exit(0);