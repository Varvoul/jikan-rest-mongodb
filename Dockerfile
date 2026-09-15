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

WORKDIR /app

# Install PHP dependencies at BUILD time so vendor/ is baked into the image.
# Composer files are copied first so this layer is cached across code-only changes.
# Flags mirror the historically-working entrypoint install (--no-scripts avoids
# ocramius/package-versions plugin issues; --ignore-platform-reqs because ext-mongodb
# is compiled from source above).
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_NO_AUDIT=1
COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --prefer-dist \
    --ignore-platform-reqs \
    --optimize-autoloader \
    && test -f vendor/autoload.php

# Copy the rest of the application code (does NOT remove the vendor/ layer above)
COPY . /app

# Create storage directories (writable at build time for the image layer)
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

# Copy entrypoint script
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/docker-entrypoint.sh"]
