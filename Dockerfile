FROM php:8.0-cli
RUN apt-get update && apt-get install -y unzip curl git && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1 > /app/public/build.log || true
COPY . .
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public