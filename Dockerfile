FROM php:8.0-cli

RUN apt-get update && apt-get install -y \
    unzip curl git libcurl4-openssl-dev pkg-config libssl-dev ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install curl \
    && rm -rf /var/lib/apt/lists/*

RUN curl -L -o /tmp/mongodb.tgz https://pecl.php.net/get/mongodb-1.15.3.tgz \
    && tar -xzf /tmp/mongodb.tgz -C /tmp \
    && cd /tmp/mongodb-1.15.3 \
    && phpize \
    && ./configure --with-php-config=/usr/local/bin/php-config \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable mongodb \
    && rm -rf /tmp/mongodb*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

COPY . .
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

# Run composer at startup if needed, then start server
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 10000
CMD ["/docker-entrypoint.sh"]