#!/bin/bash
set -e

cd /app

# ALWAYS do a fresh install to avoid cached incompatible versions
rm -rf vendor composer.lock

echo "[entrypoint] Running composer install..."
COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs \
    2>&1 | tee /tmp/composer-install.log

# Show installed mongodb version
echo "[entrypoint] Installed mongodb/mongodb version:"
php -r "
\$data = json_decode(file_get_contents('/app/vendor/composer/installed.json'), true);
foreach (\$data as \$pkg) {
    if (isset(\$pkg['name']) && \$pkg['name'] === 'mongodb/mongodb') {
        echo \$pkg['version'] . PHP_EOL;
    }
}
" 2>&1 || true

echo "[entrypoint] composer install completed"

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public