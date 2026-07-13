#!/bin/bash
set -e

cd /app

# Install PHP dependencies at runtime (RUN-time composer install silently fails on Render free tier)
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "[entrypoint] vendor/ not found, running composer install..."
    COMPOSER_MEMORY_LIMIT=-1 composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --ignore-platform-reqs \
        2>&1 | tee /tmp/composer-install.log
    echo "[entrypoint] composer install completed"
else
    echo "[entrypoint] vendor/ exists, skipping composer install"
fi

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public