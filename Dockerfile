FROM php:8.0-cli

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

# Install mongodb extension
RUN pecl install mongodb \
    && docker-php-ext-enable mongodb

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

# Copy composer files first
COPY composer.json ./

# Install PHP dependencies (ignore platform reqs for cross-version compat)
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs

# Copy application files
COPY . .

# Set permissions
RUN mkdir -p storage/framework/cache storage/logs storage/app \
    && chmod -R 777 storage

EXPOSE 10000

CMD sh -c "php -S 0.0.0.0:${PORT:-10000} -t public"