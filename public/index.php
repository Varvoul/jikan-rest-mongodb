<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Auto-install composer dependencies if vendor/ doesn't exist
if (!file_exists(__DIR__.'/../vendor/autoload.php')) {
    header('Content-Type: text/plain');
    echo "Installing composer dependencies...\n\n";
    passthru('cd /app && composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1', $retcode);
    echo "\n\nComposer exit code: $retcode\n";
    if ($retcode !== 0) {
        echo "COMPOSER INSTALL FAILED\n";
        exit(1);
    }
}

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();