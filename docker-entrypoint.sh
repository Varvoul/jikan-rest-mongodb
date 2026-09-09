#!/bin/bash
set -e

cd /app

echo "[entrypoint] Starting Jikan API container initialization..."

# Ensure storage exists for error logging
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

install_success=0
MAX_RETRIES=3

# Function to run composer install with retries
run_composer_install() {
    local attempt=1
    while [ $attempt -le $MAX_RETRIES ]; do
        echo "[entrypoint] Composer install attempt $attempt/$MAX_RETRIES..."
        
        if [ -f "composer.lock" ]; then
            echo "[entrypoint] Installing from lock file..."
            COMPOSER_MEMORY_LIMIT=-1 composer install \
                --no-dev \
                --no-interaction \
                --no-scripts \
                --prefer-dist \
                --ignore-platform-reqs \
                2>&1 | tee /tmp/composer-install.log
            
            if [ ${PIPESTATUS[0]} -eq 0 ] && [ -f "vendor/autoload.php" ]; then
                return 0
            fi
            echo "[entrypoint] Lock file install failed, will retry without lock..."
            rm -f composer.lock
        fi
        
        # Fresh install without lock
        echo "[entrypoint] Running fresh composer install..."
        rm -rf vendor 2>/dev/null || true
        
        COMPOSER_MEMORY_LIMIT=-1 composer install \
            --no-dev \
            --no-interaction \
            --no-scripts \
            --prefer-dist \
            --ignore-platform-reqs \
            --no-cache \
            2>&1 | tee /tmp/composer-install.log
        
        if [ ${PIPESTATUS[0]} -eq 0 ] && [ -f "vendor/autoload.php" ]; then
            return 0
        fi
        
        attempt=$((attempt + 1))
        if [ $attempt -le $MAX_RETRIES ]; then
            echo "[entrypoint] Retry $attempt/$MAX_RETRIES after 10s sleep..."
            sleep 10
        fi
    done
    return 1
}

# Run composer install
if run_composer_install; then
    install_success=1
    echo "[entrypoint] ✅ Composer install succeeded!"
else
    echo "[entrypoint] ❌ Composer install failed after $MAX_RETRIES attempts"
    echo "[entrypoint] Last 30 lines of install log:"
    tail -30 /tmp/composer-install.log 2>/dev/null || echo "No log available"
    echo "COMPOSER_INSTALL_FAILED" > /app/storage/composer_error.txt
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
    if [ -f /app/patch-related.php ]; then
        echo "[entrypoint] Patching Jikan AnimeParser::getRelated()..."
        php /app/patch-related.php 2>&1 | tee /tmp/patch-related.log || true
    fi

    # Patch jikan-me/jikan AnimeParser for new MAL external links format
    if [ -f /app/patch-external.php ]; then
        echo "[entrypoint] Patching Jikan AnimeParser::getExternalLinks()..."
        php /app/patch-external.php 2>&1 | tee /tmp/patch-external.log || true
    fi
    
    echo "[entrypoint] ✅ All patches applied!"
else
    echo "[entrypoint] ⚠️ WARNING: vendor/autoload.php not found, skipping patches"
    echo "[entrypoint] The API will not work without composer dependencies!"
fi

# Show installed mongodb version if available
if [ -f "vendor/autoload.php" ]; then
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

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php ${PHP_EXTRA:-} -S 0.0.0.0:10000 -t public
