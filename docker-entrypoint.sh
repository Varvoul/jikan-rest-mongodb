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
    echo "[entrypoint] Step 2: replacing mongodb/mongodb with 1.14.4 (PHP 8.0 compatible)..."
    # Composer may resolve to 1.17+ which uses PHP 8.1+ syntax
    # Manually download 1.14.4 which is known PHP 8.0 compatible
    rm -rf /tmp/mongodb-pkg /app/vendor/mongodb
    mkdir -p /tmp/mongodb-pkg
    
    curl -sSL "https://repo.packagist.org/p/mongodb/mongodb%2445211b04ac389ad51bb68634c82178a9e98b40b0f4f74c4447eb7671e70c529e.zip" \
        -o /tmp/mongodb-pkg/mongodb.zip 2>&1 && \
    unzip -q /tmp/mongodb-pkg/mongodb.zip -d /tmp/mongodb-pkg 2>&1
    
    # Try direct packagist download
    if [ ! -d "/tmp/mongodb-pkg/mongodb" ]; then
        echo "[entrypoint] Packagist hash download failed, trying github release..."
        curl -sSL "https://github.com/mongodb/mongo-php-library/archive/refs/tags/1.14.4.tar.gz" \
            -o /tmp/mongodb-pkg/mongodb.tar.gz 2>&1 && \
        tar xzf /tmp/mongodb-pkg/mongodb.tar.gz -C /tmp/mongodb-pkg 2>&1 && \
        mv /tmp/mongodb-pkg/mongo-php-library-1.14.4 /tmp/mongodb-pkg/mongodb 2>&1
    fi
    
    if [ -d "/tmp/mongodb-pkg/mongodb" ]; then
        # Remove the incompatible version installed by composer
        rm -rf /app/vendor/mongodb/mongodb
        cp -r /tmp/mongodb-pkg/mongodb /app/vendor/mongodb/mongodb
        # Regenerate autoloader for the replaced package
        composer dump-autoload --no-dev --no-scripts --ignore-platform-reqs 2>&1
        echo "[entrypoint] mongodb/mongodb manually replaced with 1.14.4"
    else
        echo "[entrypoint] WARNING: Could not download mongodb/mongodb 1.14.4, keeping composer version"
    fi
    
    # Cleanup
    rm -rf /tmp/mongodb-pkg
    
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