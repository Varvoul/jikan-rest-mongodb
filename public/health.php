<?php
// Quick diagnostic endpoint - remove after debugging
if (isset($_GET['_health'])) {
    header('Content-Type: application/json');
    $info = [
        'php_version' => PHP_VERSION,
        'vendor_exists' => is_dir(__DIR__ . '/../vendor'),
        'autoload_exists' => file_exists(__DIR__ . '/../vendor/autoload.php'),
        'mongodb_ext' => extension_loaded('mongodb') ? 'loaded' : 'NOT loaded',
        'composer_error' => file_exists(__DIR__ . '/../storage/composer_error.txt') 
            ? file_get_contents(__DIR__ . '/../storage/composer_error.txt') 
            : 'none',
    ];
    
    if (file_exists('/tmp/composer-install.log')) {
        $info['composer_log_tail'] = implode("\n", array_slice(explode("\n", file_get_contents('/tmp/composer-install.log')), -20));
    }
    
    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}