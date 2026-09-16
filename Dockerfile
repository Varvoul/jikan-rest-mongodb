FROM php:8.1-cli
# NOTE: php:8.0-cli was based on Debian 11 (bullseye) which went EOL 2026-08-31;
# its security mirror now 404s (libssl-dev/libcurl4) and breaks every build.
# php:8.1-cli is Debian 12 (bookworm, supported until 2028). PHP 8.1 is one
# minor above 8.0 — the app already handles 8.1 syntax (readonly strip + polyfill
# in docker-entrypoint.sh are harmless no-ops on 8.1).

# Install system dependencies needed for mongodb extension compilation
RUN apt-get update && apt-get install -y \
    libssl-dev \
    libsasl2-dev \
    libcurl4-openssl-dev \
    git \
    unzip \
    curl \
    && rm -rf /var/lib/apt/lists/*

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

# Build mongodb PHP extension from source (pecl fails on Render due to SSL issues)
RUN MONGODB_VERSION="1.15.3" && \
    cd /tmp && \
    curl -sSL "https://pecl.php.net/get/mongodb-${MONGODB_VERSION}.tgz" -o mongodb.tgz && \
    tar xzf mongodb.tgz && \
    cd mongodb-${MONGODB_VERSION} && \
    phpize && \
    ./configure --with-mongodb-ssl=openssl && \
    make -j"$(nproc)" && \
    make install && \
    docker-php-ext-enable mongodb && \
    cd / && \
    rm -rf /tmp/mongodb*

# Verify mongodb extension is loaded
RUN php -m | grep mongodb

WORKDIR /app

# Install PHP dependencies at BUILD time so vendor/ is baked into the image.
# Only composer.json is copied: the historical composer.lock was internally
# inconsistent (doctrine/lexer & psr/log locked at majors conflicting with the
# locked dependents' constraints) and made `composer install` fail validation.
# The previous runtime workaround deleted the lock and resolved fresh — we do
# the same here, deterministically. jikan-me/jikan is pinned exactly in
# composer.json so fresh resolution cannot drift the parser version.
# Flags mirror the historically-working entrypoint install (--no-scripts avoids
# ocramius/package-versions plugin issues; --ignore-platform-reqs because ext-mongodb
# is compiled from source above).
ENV COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1 \
    COMPOSER_NO_AUDIT=1
COPY composer.json ./
RUN rm -f composer.lock && composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-progress \
    --prefer-dist \
    --ignore-platform-reqs \
    --optimize-autoloader \
    && test -f vendor/autoload.php \
# jms/serializer 1.x declares `final class ReadOnly` — `readonly` became a
# reserved class name in PHP 8.1, so that file and AnnotationDriver.php (which
# references it) fail to parse and every cache-miss request 500s with
# "syntax error, unexpected token \"readonly\"". The annotation is unused by
# this app's models, so rename the class and refresh the optimized classmap.
# Mirrored (guarded, idempotent) in docker-entrypoint.sh for the runtime
# fallback install path.
    && if [ -f vendor/jms/serializer/src/JMS/Serializer/Annotation/ReadOnly.php ]; then \
        mv vendor/jms/serializer/src/JMS/Serializer/Annotation/ReadOnly.php \
           vendor/jms/serializer/src/JMS/Serializer/Annotation/ReadOnlyAnnotation.php \
        && sed -i 's/\bReadOnly\b/ReadOnlyAnnotation/g' \
           vendor/jms/serializer/src/JMS/Serializer/Annotation/ReadOnlyAnnotation.php \
        && sed -i 's/\bReadOnly\b/ReadOnlyAnnotation/g' \
           vendor/jms/serializer/src/JMS/Serializer/Metadata/Driver/AnnotationDriver.php \
        && composer dump-autoload -o >/dev/null 2>&1 || true; \
    fi \
    && php -l vendor/jms/serializer/src/JMS/Serializer/Annotation/ReadOnlyAnnotation.php \
    && php -l vendor/jms/serializer/src/JMS/Serializer/Metadata/Driver/AnnotationDriver.php

# Copy the rest of the application code (does NOT remove the vendor/ layer above)
COPY . /app

# Create storage directories (writable at build time for the image layer)
RUN mkdir -p storage/framework/cache storage/logs storage/app && chmod -R 777 storage

# Copy entrypoint script
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN chmod +x /docker-entrypoint.sh

EXPOSE 10000

ENTRYPOINT ["/docker-entrypoint.sh"]
# Force rebuild Wed Sep 16 18:12:45 UTC 2026
