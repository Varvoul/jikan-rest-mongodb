FROM composer:2.2 as vendor

WORKDIR /app
COPY composer.json composer.lock* ./

# Install PHP extensions required for mongodb in the composer stage
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    unzip \
    git \
    && docker-php-ext-install curl \
    && pecl install mongodb-1.15.0 \
    && docker-php-ext-enable mongodb

# Install dependencies without dev
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs

# Application stage
FROM php:7.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN apt-get update \
    && docker-php-ext-install curl \
    && pecl install mongodb-1.15.0 \
    && docker-php-ext-enable mongodb \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Copy vendor from build stage
COPY --from=vendor /app/vendor ./vendor

# Copy application files
COPY . .

# Set permissions
RUN mkdir -p storage/framework/cache storage/logs storage/app \
    && chown -R www-data:www-data storage \
    && chmod -R 777 storage

# Expose port
EXPOSE 10000

# Use PHP built-in server for Lumen
CMD ["php", "-S", "0.0.0.0:10000", "-t", "public"]