#!/bin/bash

cd /app

# Remove old artifacts
rm -rf vendor composer.lock

echo "[entrypoint] Step 1: composer install..."
COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs \
    --no-cache \
    2>&1 | tee /tmp/composer-install.log

COMPOSER_EXIT=${PIPESTATUS[0]}

if [ $COMPOSER_EXIT -ne 0 ]; then
    echo "[entrypoint] ERROR: composer install failed (exit $COMPOSER_EXIT)"
    echo "COMPOSER_INSTALL_FAILED" > /app/storage/composer_error.txt
    tail -20 /tmp/composer-install.log >> /app/storage/composer_error.txt
else
    echo "[entrypoint] Step 2: PHP 8.0 compatibility patch..."
    # mongodb/mongodb 1.21.x uses 'readonly' properties (PHP 8.1+ only)
    # Remove readonly keyword to make it PHP 8.0 compatible
    if [ -d /app/vendor/mongodb/mongodb/src ]; then
        FIND_COUNT=$(grep -rl 'readonly' /app/vendor/mongodb/mongodb/src/ --include="*.php" 2>/dev/null | wc -l)
        if [ "$FIND_COUNT" -gt 0 ]; then
            echo "[entrypoint] Patching $FIND_COUNT files with 'readonly' keyword..."
            find /app/vendor/mongodb/mongodb/src -name "*.php" -exec sed -i 's/\breadonly //g' {} +
            echo "[entrypoint] Patch applied: removed 'readonly' keyword"
            
            # Verify patch
            REMAINING=$(grep -rl 'readonly' /app/vendor/mongodb/mongodb/src/ --include="*.php" 2>/dev/null | wc -l)
            echo "[entrypoint] Remaining files with 'readonly': $REMAINING"
            
            # Verify syntax of the patched Client.php
            SYNTAX_CHECK=$(php -l /app/vendor/mongodb/mongodb/src/Client.php 2>&1)
            echo "[entrypoint] Client.php syntax: $SYNTAX_CHECK"
        else
            echo "[entrypoint] No 'readonly' found, patching not needed"
        fi
    else
        echo "[entrypoint] WARNING: vendor/mongodb/mongodb/src not found"
    fi
    
    # Show installed version
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
fi

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
# Load polyfill.php before any PHP file to provide PHP 8.1+ function shims
exec php -d auto_prepend_file=/app/polyfill.php -S 0.0.0.0:10000 -t public