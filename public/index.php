<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Suppress E_DEPRECATED/E_USER_DEPRECATED from the very first line: on PHP 8.1,
// loading Lumen 5.8's vendor classes (Illuminate\Container implements ArrayAccess
// with pre-8.1 signatures) prints deprecation notices DURING autoload — before
// bootstrap/app.php gets a chance to set error_reporting. That output corrupts
// headers and JSON bodies ("Cannot modify header information" / empty 200s).
// 8191 = E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED on PHP 8.1.
error_reporting(E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED);

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();