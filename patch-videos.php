<?php

/**
 * Runtime patch (t153): apply null-safe video parsers to jikan-me/jikan 2.19.4
 * so /v4/anime/{id}/videos survives MAL's redesigned /anime/{id}/_/video page
 * (span.title -> span.title.js-color-pc-constant broke the exact-class XPath
 * and 500'd every request with "Failed to parse .../video").
 *
 * Idempotent (PATCHED-T153 marker), lint-verified (php -l), restores the
 * original file if lint fails. Invoked by docker-entrypoint.sh on every boot;
 * the same files are also baked into vendor/ by the Dockerfile right after
 * COPY . /app.
 */

$vendorDir = '/app/vendor/jikan-me/jikan/src/Parser/Anime';
$patchDir  = '/app/patches/jikan-v2';

$targets = [
    'StreamEpisodeListItemParser.php',
    'PromoListItemParser.php',
];

$applied = 0;
foreach ($targets as $file) {
    $target = $vendorDir . '/' . $file;
    $patch  = $patchDir . '/' . $file;

    if (!file_exists($target)) {
        echo "[patch-videos] SKIP (missing vendor file): {$file}\n";
        continue;
    }
    if (!file_exists($patch)) {
        echo "[patch-videos] SKIP (missing patch file): {$patch}\n";
        continue;
    }

    $current = @file_get_contents($target);
    if ($current !== false && strpos($current, 'PATCHED-T153') !== false) {
        echo "[patch-videos] already patched: {$file}\n";
        $applied++;
        continue;
    }

    $backup = $target . '.pre-t153';
    if (!file_exists($backup)) {
        @copy($target, $backup);
    }

    if (!@copy($patch, $target)) {
        echo "[patch-videos] ERROR: could not write {$target}\n";
        continue;
    }

    $out = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($target) . ' 2>&1', $out, $code);
    $lint = implode("\n", $out);
    if ($code !== 0) {
        @copy($backup, $target);
        echo "[patch-videos] LINT FAILED — restored original: {$file}\n{$lint}\n";
        continue;
    }

    echo "[patch-videos] patched: {$file}\n";
    $applied++;
}

echo "[patch-videos] done ({$applied}/" . count($targets) . ")\n";
if (0 === $applied) {
    exit(1);
}
