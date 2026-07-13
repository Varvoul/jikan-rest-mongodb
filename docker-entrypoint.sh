#!/bin/bash

cd /app

# Install PHP dependencies from the committed composer.lock (mongodb/mongodb pinned to 1.20.0, PHP 8.0 native)
if [ ! -d "vendor" ] || [ ! -f "vendor/autoload.php" ]; then
    echo "[entrypoint] Running composer install from lock file..."
    COMPOSER_MEMORY_LIMIT=-1 composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --ignore-platform-reqs \
        2>&1 | tee /tmp/composer-install.log

    COMPOSER_EXIT=${PIPESTATUS[0]}
    echo "[entrypoint] composer install exit code: $COMPOSER_EXIT"

    if [ $COMPOSER_EXIT -ne 0 ]; then
        echo "[entrypoint] ERROR: composer install failed (exit $COMPOSER_EXIT)"
        echo "COMPOSER_INSTALL_FAILED" > /app/storage/composer_error.txt
        tail -20 /tmp/composer-install.log >> /app/storage/composer_error.txt
    fi

    # Show installed mongodb version
    php -r "
        \$f = '/app/vendor/composer/installed.json';
        if (file_exists(\$f)) {
            \$data = json_decode(file_get_contents(\$f), true);
            foreach (\$data as \$p) {
                if (isset(\$p['name']) && \$p['name'] === 'mongodb/mongodb') {
                    echo '[entrypoint] mongodb/mongodb: ' . (\$p['version'] ?? '?') . PHP_EOL;
                }
            }
        }
    " 2>&1 || true
else
    echo "[entrypoint] vendor/ exists, skipping composer install"
fi

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public