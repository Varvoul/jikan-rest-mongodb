#!/bin/bash

cd /app

# Ensure storage exists for error logging
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

install_success=0

if [ -f "composer.lock" ]; then
    echo "[entrypoint] Attempting composer install from lock file..."
    COMPOSER_MEMORY_LIMIT=-1 composer install \
        --no-dev \
        --no-interaction \
        --no-scripts \
        --prefer-dist \
        --ignore-platform-reqs \
        2>&1 | tee /tmp/composer-lock-install.log

    if [ ${PIPESTATUS[0]} -eq 0 ] && [ -f "vendor/autoload.php" ]; then
        install_success=1
        echo "[entrypoint] Lock file install succeeded"
    else
        echo "[entrypoint] Lock file install failed, removing lock and retrying..."
        rm -f composer.lock
    fi
fi

if [ $install_success -eq 0 ]; then
    echo "[entrypoint] Running composer install (fresh resolve)..."
    rm -rf vendor composer.lock
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
        install_success=1
        echo "[entrypoint] Install succeeded"
    fi
fi

# === Apply runtime patches (runs regardless of install path) ===
if [ $install_success -eq 1 ] && [ -f "vendor/autoload.php" ]; then
    echo "[entrypoint] Applying runtime patches..."

    # Patch mongodb/mongodb if it has PHP 8.1+ syntax
    if [ -d /app/vendor/mongodb/mongodb/src ]; then
        # Check for 'readonly' keyword (PHP 8.1+)
        READONLY_COUNT=$(grep -rl 'readonly' /app/vendor/mongodb/mongodb/src/ --include="*.php" 2>/dev/null | wc -l)
        if [ "$READONLY_COUNT" -gt 0 ]; then
            echo "[entrypoint] Removing 'readonly' keyword from $READONLY_COUNT files..."
            find /app/vendor/mongodb/mongodb/src -name "*.php" -exec sed -i 's/\breadonly //g' {} +
        fi

        # Check for array_is_list usage (PHP 8.1+)
        ARRAY_IS_LIST=$(grep -rl 'array_is_list' /app/vendor/mongodb/mongodb/src/ --include="*.php" 2>/dev/null | wc -l)
        if [ "$ARRAY_IS_LIST" -gt 0 ]; then
            echo "[entrypoint] Creating array_is_list polyfill..."
            cat > /app/polyfill.php << 'POLYFILL'
<?php
if (!function_exists('array_is_list')) {
    function array_is_list(array $arr): bool {
        if (empty($arr)) return true;
        return array_keys($arr) === range(0, count($arr) - 1);
    }
}
POLYFILL
            PHP_EXTRA="-d auto_prepend_file=/app/polyfill.php"
        fi
    fi

    # Patch jikan-me/jikan AnimeParser for new MAL HTML structure
    # MAL removed the "anime_detail_related_anime" class; now uses "related-entries" div
    if [ -f /app/patch-related.php ]; then
        echo "[entrypoint] Patching Jikan AnimeParser::getRelated() for new MAL HTML..."
        php /app/patch-related.php 2>&1 | tee /tmp/patch-related.log
        PATCH_EXIT=${PIPESTATUS[0]}
        if [ $PATCH_EXIT -ne 0 ]; then
            echo "[entrypoint] WARNING: patch-related.php exited with code $PATCH_EXIT"
        fi
    else
        echo "[entrypoint] WARNING: patch-related.php not found, skipping parser patch"
    fi

    # Patch jikan-me/jikan AnimeParser for new MAL external links format
    if [ -f /app/patch-external.php ]; then
        echo "[entrypoint] Patching Jikan AnimeParser::getExternalLinks() for new MAL HTML..."
        php /app/patch-external.php 2>&1 | tee /tmp/patch-external.log
        PATCH_EXIT=${PIPESTATUS[0]}
        if [ $PATCH_EXIT -ne 0 ]; then
            echo "[entrypoint] WARNING: patch-external.php exited with code $PATCH_EXIT"
        fi
    fi
else
    echo "[entrypoint] WARNING: vendor/autoload.php not found, skipping patches"
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

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php ${PHP_EXTRA:-} -S 0.0.0.0:10000 -t public