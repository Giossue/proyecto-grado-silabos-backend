FROM docker.io/library/composer:2 AS php-deps
WORKDIR /app
COPY composer.json composer.lock ./
COPY . .
RUN composer install \
        --no-dev \
        --no-scripts \
        --prefer-dist \
        --no-interaction \
        --ignore-platform-req=ext-gd \
    && composer dump-autoload --optimize --classmap-authoritative --no-dev

FROM docker.io/library/php:8.4-fpm-alpine3.23 AS runtime

RUN apk add --no-cache \
        nginx \
        supervisor \
        libpq-dev \
        icu-dev \
        libzip-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        poppler-utils \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_pgsql \
        intl \
        zip \
        gd \
        opcache \
        pcntl \
    && apk del libpq-dev icu-dev libzip-dev freetype-dev libjpeg-turbo-dev libpng-dev \
    && apk add --no-cache libpq icu-libs libzip freetype libjpeg-turbo libpng

WORKDIR /var/www/html

COPY --from=php-deps /app/vendor ./vendor
COPY . .

COPY docker/app/nginx.conf /etc/nginx/nginx.conf
COPY docker/app/php.ini /usr/local/etc/php/conf.d/silabos.ini
COPY docker/app/php-fpm.conf /usr/local/etc/php-fpm.d/zz-silabos.conf
COPY docker/app/supervisord.conf /etc/supervisord.conf
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

RUN mkdir -p \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/testing \
        storage/framework/views \
        storage/logs \
        storage/app/private \
        storage/app/public \
        bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

RUN chown -R www-data:www-data /var/lib/nginx

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://127.0.0.1:8080/health/live || exit 1

ENTRYPOINT ["entrypoint"]
CMD ["supervisord", "--configuration", "/etc/supervisord.conf", "--nodaemon"]
