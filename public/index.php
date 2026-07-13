<?php
/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
*/

// Debug endpoint
if (isset($_GET['dbg'])) {
    header('Content-Type: text/plain');
    echo "PHP Version: " . PHP_VERSION . "\n";
    echo "MongoDB ext: " . (extension_loaded('mongodb') ? 'LOADED' : 'NOT LOADED') . "\n";
    echo "Vendor dir: " . (is_dir(__DIR__.'/../vendor') ? 'EXISTS' : 'MISSING') . "\n";
    echo "Working dir: " . getcwd() . "\n";
    passthru('ls -la /app/ 2>&1');
    echo "\n--- ComposER LOCK ---\n";
    echo file_exists('/app/composer.lock') ? "EXISTS" : "MISSING";
    exit;
}

if (!file_exists(__DIR__.'/../vendor/autoload.php')) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dependencies not installed. Waiting for composer install...\n";
    echo "Visit /?dbg for debug info.\n";
    exit(1);
}

$app = require __DIR__.'/../bootstrap/app.php';

ob_start("ob_gzhandler");

if (!env('APP_DEBUG')) {
    header("Content-Type: application/json");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: *");
}

$app->run();