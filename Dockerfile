FROM php:8.0-cli

# Install system dependencies needed for mongodb extension compilation
RUN apt-get update && apt-get install -y \
    libssl-dev \
    libsasl2-dev \
    libcurl4-openssl-dev \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Build mongodb PHP extension from source (pecl fails on Render due to SSL issues)
RUN MONGODB_VERSION="1.15.3" && \
    cd /tmp && \
    curl -sSL "https://pecl.php.net/get/mongodb-${MONGODB_VERSION}.tgz" -o mongodb.tgz && \
    tar xzf mongodb.tgz && \
    cd mongodb-${MONGODB_VERSION} && \
    phpize && \
    ./configure --with-mongodb-ssl=openssl && \
    make -j"$(nproc)" && \
    make install && \
    docker-php-ext-enable mongodb && \
    cd / && \
    rm -rf /tmp/mongodb*

# Verify mongodb extension is loaded
RUN php -m | grep mongodb

# Copy application code FIRST
COPY . /app
WORKDIR /app

# Create storage directories
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

# =====================================================
# FIX: Run composer install DURING BUILD (not just runtime)
# This ensures vendor/ folder is baked into the image
# =====================================================
RUN COMPOSER_MEMORY_LIMIT=-1 composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --prefer-dist \
    --ignore-platform-reqs \
    --optimize-autoloader \
    2>&1

# Verify vendor/autoload.php exists
RUN test -f vendor/autoload.php || (echo "ERROR: vendor/autoload.php not found!" && exit 1)

# Copy entrypoint script
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/docker-entrypoint.sh"]
