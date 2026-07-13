<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Debug: log env vars availability
file_put_contents(__DIR__.'/../storage/logs/env_debug.log', 
    "MONGODB_URI=" . (getenv('MONGODB_URI') ?: 'NULL') . "\n" .
    "CACHE_DRIVER=" . (getenv('CACHE_DRIVER') ?: 'NULL') . "\n" .
    "SERVER_NAME=" . ($_SERVER['SERVER_NAME'] ?? 'NULL') . "\n" .
    "HTTP_HOST=" . ($_SERVER['HTTP_HOST'] ?? 'NULL') . "\n"
);

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();