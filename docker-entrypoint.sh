#!/bin/bash

cd /app

# Remove old artifacts
rm -rf vendor composer.lock

echo "[entrypoint] Step 1: composer install (resolves all dependencies)..."
COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs \
    --no-cache \
    2>&1 | tee /tmp/composer-install.log

COMPOSER_EXIT=${PIPESTATUS[0]}
echo "[entrypoint] composer install exit code: $COMPOSER_EXIT"

if [ $COMPOSER_EXIT -ne 0 ]; then
    echo "[entrypoint] ERROR: composer install failed!"
    echo "COMPOSER_INSTALL_FAILED exit=$COMPOSER_EXIT" > /app/storage/composer_error.txt
    tail -20 /tmp/composer-install.log >> /app/storage/composer_error.txt
else
    echo "[entrypoint] Step 2: downgrading mongodb/mongodb to ~1.14.0 (PHP 8.0 compatible)..."
    COMPOSER_MEMORY_LIMIT=-1 composer require mongodb/mongodb:'~1.14.0' \
        --no-interaction \
        --no-scripts \
        --ignore-platform-reqs \
        --no-cache \
        --with-all-dependencies \
        2>&1 | tee /tmp/composer-downgrade.log
    
    DOWNGRADE_EXIT=${PIPESTATUS[0]}
    echo "[entrypoint] downgrade exit code: $DOWNGRADE_EXIT"
    
    if [ $DOWNGRADE_EXIT -ne 0 ]; then
        echo "[entrypoint] WARNING: mongodb downgrade failed, continuing with installed version"
        tail -10 /tmp/composer-downgrade.log
    fi
    
    # Show installed mongodb version
    php -r "
        \$f = '/app/vendor/composer/installed.json';
        if (file_exists(\$f)) {
            \$data = json_decode(file_get_contents(\$f), true);
            foreach (\$data as \$p) {
                if (isset(\$p['name']) && \$p['name'] === 'mongodb/mongodb') {
                    echo '[entrypoint] mongodb/mongodb installed: ' . (\$p['version'] ?? '?') . PHP_EOL;
                }
            }
        }
    " 2>&1 || true
fi

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public