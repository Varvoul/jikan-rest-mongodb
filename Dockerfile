FROM php:7.4-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    unzip \
    git \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install PHP curl extension
RUN docker-php-ext-install curl

# Install mongodb extension (use latest compatible with PHP 7.4)
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install composer
COPY --from=composer:2.2 /usr/bin/composer /usr/local/bin/composer

WORKDIR /app

# Copy composer files first for Docker layer caching
COPY composer.json ./

# Install PHP dependencies
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1 || \
    (echo "RETRYING composer install..." && rm -rf vendor composer.lock && composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1)

# Copy application files
COPY . .

# Set permissions for storage
RUN mkdir -p storage/framework/cache storage/logs storage/app \
    && chmod -R 777 storage

# Expose port
EXPOSE 10000

# Use PHP built-in server
CMD sh -c "php -S 0.0.0.0:${PORT:-10000} -t public"