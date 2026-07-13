FROM php:8.0-cli

# System dependencies + mongodb extension + composer
RUN apt-get update && apt-get install -y \
    unzip curl git libcurl4-openssl-dev pkg-config libssl-dev ca-certificates \
    && update-ca-certificates \
    && docker-php-ext-install curl \
    && curl -L -o /tmp/mongodb.tgz https://pecl.php.net/get/mongodb-1.15.3.tgz \
    && tar -xzf /tmp/mongodb.tgz -C /tmp \
    && cd /tmp/mongodb-1.15.3 \
    && phpize \
    && ./configure --with-php-config=/usr/local/bin/php-config \
    && make -j$(nproc) \
    && make install \
    && docker-php-ext-enable mongodb \
    && rm -rf /tmp/mongodb* \
    && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && rm -rf /var/lib/apt/lists/*

COPY . /app
WORKDIR /app

# Install PHP dependencies
RUN COMPOSER_MEMORY_LIMIT=-1 composer install --no-dev --no-interaction --no-scripts --prefer-dist --ignore-platform-reqs

# Patch Lumen 5.8 Container.php for PHP 8.0 (ReflectionParameter::getClass deprecated)
RUN php -r '
    $f = "/app/vendor/illuminate/container/Container.php";
    $c = file_get_contents($f);
    $c = str_replace(
        "if (!is_null($param->getClass())) {\n                            $class = $param->getClass()->getName();",
        "if ($param->getType() && !$param->getType()->isBuiltin()) {\n                            $class = $param->getType()->getName();",
        $c
    );
    file_put_contents($f, $c);
    echo "Patched\n";
' 2>&1 || echo "Patch skipped"

# Set permissions
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public