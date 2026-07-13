<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Suppress PHP 8.0 deprecation warnings for Lumen 5.8 compatibility
error_reporting(E_ALL & ~E_DEPRECATED);

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();