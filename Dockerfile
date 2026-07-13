FROM php:8.0-cli
COPY . /app
WORKDIR /app
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public