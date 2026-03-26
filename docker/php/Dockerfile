FROM php:8.2-cli-alpine

RUN apk add --no-cache \
        postgresql-dev \
        icu-dev \
        unzip \
    && docker-php-ext-install pdo_pgsql intl opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP dependencies as a separate cached layer
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader

# Copy application source (vendor/ is excluded via .dockerignore)
COPY . .

RUN composer dump-autoload --no-dev --optimize \
    && mkdir -p var/uploads var/cache var/log

COPY docker/php/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 8000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app/public"]
