FROM php:8.0-cli
RUN apt-get update && apt-get install -y \
    unzip curl git libcurl4-openssl-dev pkg-config libssl-dev ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install curl \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*
COPY . .
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public