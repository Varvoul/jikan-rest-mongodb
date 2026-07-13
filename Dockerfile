FROM php:8.1-cli
ARG BUILD_VERSION=6

# System dependencies + mongodb extension + composer
RUN apt-get update && apt-get install -y \
    unzip curl git libcurl4-openssl-dev pkg-config libssl-dev ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install curl \
    && curl -L -o /tmp/mongodb.tgz https://pecl.php.net/get/mongodb-1.15.4.tgz \
    && tar -xzf /tmp/mongodb.tgz -C /tmp \
    && cd /tmp/mongodb-1.15.4 \
    && phpize \
    && ./configure --with-php-config=/usr/local/bin/php-config \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable mongodb \
    && rm -rf /tmp/mongodb* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

COPY . /app
WORKDIR /app

# Force invalidation of composer layer
RUN echo "Build v${BUILD_VERSION}" > /app/build_version.txt \
    && COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs \
    && rm -f /app/build_version.txt

# Set permissions
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public