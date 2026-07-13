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

# Install PHP extensions
RUN docker-php-ext-install curl

# Install mongodb extension
RUN pecl install mongodb-1.15.3 \
    && docker-php-ext-enable mongodb

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy composer files first for layer caching
COPY composer.json ./

# Install PHP dependencies (ignore platform reqs since we know our setup)
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs 2>&1

# Copy application files
COPY . .

# Set permissions for storage
RUN mkdir -p storage/framework/cache storage/logs storage/app \
    && chmod -R 777 storage

# Expose port (Render provides PORT env var)
EXPOSE 10000

# Use PHP built-in server
CMD sh -c "php -S 0.0.0.0:${PORT:-10000} -t public"