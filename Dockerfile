FROM php:7.4-cli
RUN apt-get update && apt-get install -y unzip curl git && rm -rf /var/lib/apt/lists/*
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY . /app
WORKDIR /app
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1 | tee /app/public/clog.txt; echo "EXIT:$?" >> /app/public/clog.txt
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public