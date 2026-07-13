FROM composer:2.2 as vendor

WORKDIR /app
COPY composer.json composer.lock* ./

# Install PHP extensions required for mongodb
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    && docker-php-ext-install curl \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install dependencies without dev
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist

# Application stage
FROM php:8.1-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    unzip \
    git \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install curl \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

WORKDIR /app

# Copy vendor from build stage
COPY --from=vendor /app/vendor ./vendor

# Copy application files
COPY . .

# Set permissions
RUN mkdir -p storage/framework/cache storage/logs storage/app \
    && chown -R www-data:www-data storage

# Expose port
EXPOSE 10000

# Use PHP built-in server for Lumen
CMD ["php", "-S", "0.0.0.0:10000", "-t", "public"]