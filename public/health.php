<?php
// Diagnostic endpoint
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
    
    // Show installed mongodb library version
    $installedFile = __DIR__ . '/../vendor/composer/installed.json';
    if (file_exists($installedFile)) {
        $data = json_decode(file_get_contents($installedFile), true);
        foreach ($data as $pkg) {
            if (isset($pkg['name']) && $pkg['name'] === 'mongodb/mongodb') {
                $info['mongodb_lib_version'] = $pkg['version'] ?? 'unknown';
            }
        }
    }
    
    echo json_encode($info, JSON_PRETTY_PRINT);
    exit;
}