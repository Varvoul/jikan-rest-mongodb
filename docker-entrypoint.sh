#!/bin/bash

cd /app

# Always do a fresh install
rm -rf vendor composer.lock

echo "[entrypoint] Running composer install..."
COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs \
    --no-cache \
    2>&1 | tee /tmp/composer-install.log

COMPOSER_EXIT=${PIPESTATUS[0]}
echo "[entrypoint] composer exit code: $COMPOSER_EXIT"

if [ $COMPOSER_EXIT -ne 0 ]; then
    echo "[entrypoint] ERROR: composer install failed!"
    echo "[entrypoint] Last 50 lines of log:"
    tail -50 /tmp/composer-install.log
    # Write error to a file so the PHP server can show it
    echo "COMPOSER_INSTALL_FAILED exit=$COMPOSER_EXIT" > /app/storage/composer_error.txt
else
    echo "[entrypoint] composer install succeeded"
    # Show installed mongodb version
    if [ -f vendor/composer/installed.json ]; then
        php -r "
            \$data = json_decode(file_get_contents('/app/vendor/composer/installed.json'), true);
            foreach (\$data as \$pkg) {
                if (isset(\$pkg['name']) && \$pkg['name'] === 'mongodb/mongodb') {
                    echo '[entrypoint] mongodb/mongodb version: ' . \$pkg['version'] . PHP_EOL;
                }
            }
        " 2>&1 || true
    fi
fi

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public