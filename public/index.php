<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Debug: log env vars to public
$envLog = "MONGODB_URI=" . (getenv('MONGODB_URI') ?: 'NULL') . "\n" .
    "CACHE_DRIVER=" . (getenv('CACHE_DRIVER') ?: 'NULL') . "\n" .
    "All env keys: " . implode(', ', array_keys(array_filter($_ENV))) . "\n";
@file_put_contents(__DIR__.'/env_debug.txt', $envLog);

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();