FROM php:8.0-cli
RUN apt-get update && apt-get install -y unzip curl git && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY . .
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage
RUN echo "<?php header('Content-Type: text/plain'); echo file_get_contents('/tmp/cout.txt');" > /app/public/err.php
RUN (composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1 | tee /tmp/cout.txt; exit ${PIPESTATUS[0]}) || true
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public