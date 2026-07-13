<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Debug: log env vars
error_log("MONGODB_URI=" . (getenv('MONGODB_URI') ?: 'NULL'));
error_log("CACHE_DRIVER=" . (getenv('CACHE_DRIVER') ?: 'NULL'));
error_log("ENV_COUNT=" . count($_ENV));

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();