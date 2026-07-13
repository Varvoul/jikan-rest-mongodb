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
    # Still try to start - will show error
    mkdir -p storage/framework/cache storage/logs storage/app
    chmod -R 777 storage 2>/dev/null || true
    exec php -S 0.0.0.0:10000 -t public
fi

echo "[entrypoint] Step 2: Check mongodb/mongodb version..."
INSTALLED_MONGO_VER=$(php -r "
    \$f = '/app/vendor/composer/installed.json';
    \$data = json_decode(file_get_contents(\$f), true);
    foreach (\$data as \$p) {
        if (isset(\$p['name']) && \$p['name'] === 'mongodb/mongodb') {
            echo \$p['version'];
            break;
        }
    }
" 2>&1)
echo "[entrypoint] Currently installed: mongodb/mongodb $INSTALLED_MONGO_VER"

# Check if the installed version has the PHP 8.0 compatibility issue
# Test if Client.php can be parsed
PHP_TEST=$(php -l /app/vendor/mongodb/mongodb/src/Client.php 2>&1)
if echo "$PHP_TEST" | grep -q "No syntax errors"; then
    echo "[entrypoint] mongodb/mongodb syntax OK, no replacement needed"
else
    echo "[entrypoint] Syntax error detected, downloading PHP 8.0 compatible version..."
    echo "[entrypoint] Error was: $PHP_TEST"
    
    # Download mongo-php-library 1.14.4 from GitHub
    rm -rf /tmp/mongodb-replace
    mkdir -p /tmp/mongodb-replace
    
    echo "[entrypoint] Downloading mongo-php-library 1.14.4 from GitHub..."
    curl -sSL --max-time 120 "https://github.com/mongodb/mongo-php-library/archive/refs/tags/1.14.4.tar.gz" \
        -o /tmp/mongodb-replace/mongodb.tar.gz
    
    if [ -f /tmp/mongodb-replace/mongodb.tar.gz ] && [ -s /tmp/mongodb-replace/mongodb.tar.gz ]; then
        echo "[entrypoint] Download complete, extracting..."
        tar xzf /tmp/mongodb-replace/mongodb.tar.gz -C /tmp/mongodb-replace
        
        EXTRACTED_DIR=$(ls -d /tmp/mongodb-replace/mongo-php-library-* 2>/dev/null | head -1)
        echo "[entrypoint] Extracted to: $EXTRACTED_DIR"
        
        if [ -d "$EXTRACTED_DIR" ] && [ -f "$EXTRACTED_DIR/src/Client.php" ]; then
            echo "[entrypoint] Source has src/Client.php, proceeding with replacement..."
            echo "[entrypoint] Source structure:"
            ls -la "$EXTRACTED_DIR/src/" | head -10
            
            # Verify replacement is syntax-clean
            NEW_TEST=$(php -l "$EXTRACTED_DIR/src/Client.php" 2>&1)
            if echo "$NEW_TEST" | grep -q "No syntax errors"; then
                echo "[entrypoint] Replacement version syntax OK"
                rm -rf /app/vendor/mongodb/mongodb
                cp -r "$EXTRACTED_DIR" /app/vendor/mongodb/mongodb
                echo "[entrypoint] Files copied, verifying..."
                ls -la /app/vendor/mongodb/mongodb/src/ | head -10
                
                # Regenerate autoloader
                composer dump-autoload --no-dev --no-scripts --ignore-platform-reqs 2>&1
                echo "[entrypoint] Autoloader regenerated successfully"
            else
                echo "[entrypoint] WARNING: Replacement also has syntax errors, aborting"
                echo "$NEW_TEST"
            fi
        else
            echo "[entrypoint] WARNING: Downloaded archive missing src/Client.php"
            ls -la /tmp/mongodb-replace/ 2>&1
            ls -la "$EXTRACTED_DIR/" 2>&1 | head -10
        fi
    else
        echo "[entrypoint] WARNING: Download failed or empty file"
        ls -la /tmp/mongodb-replace/ 2>&1
    fi
    
    rm -rf /tmp/mongodb-replace
fi

# Show final installed mongodb version
php -r "
    \$f = '/app/vendor/composer/installed.json';
    if (file_exists(\$f)) {
        \$data = json_decode(file_get_contents(\$f), true);
        foreach (\$data as \$p) {
            if (isset(\$p['name']) && \$p['name'] === 'mongodb/mongodb') {
                echo '[entrypoint] Final mongodb/mongodb: ' . (\$p['version'] ?? '?') . PHP_EOL;
            }
        }
    }
" 2>&1 || true

# Ensure storage directories are writable
mkdir -p storage/framework/cache storage/logs storage/app
chmod -R 777 storage 2>/dev/null || true

echo "[entrypoint] Starting PHP built-in server on port 10000..."
exec php -S 0.0.0.0:10000 -t public