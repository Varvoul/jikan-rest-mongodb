#!/bin/sh
cd /app

if [ ! -d "vendor" ]; then
    echo "Installing PHP dependencies..."
    composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1
    echo "Exit code: $?"
fi

php -S 0.0.0.0:10000 -t public