# syntax=docker/dockerfile:1.7
#
# One Dockerfile, three targets:
#   app    production PHP-FPM image (also runs the queue worker and scheduler)
#   nginx  production web server with the compiled public/ assets baked in
#   dev    development image: bind-mount the source, composer + dev dependencies
#
# Build:  docker build --target app   -t shop-app .
#         docker build --target nginx -t shop-nginx .

ARG PHP_VERSION=8.4

# --- PHP dependencies (production) -----------------------------------------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction

# --- Base runtime ----------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base
COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql opcache pcntl redis \
    && apk add --no-cache tini \
    && mkdir -p /var/www/html && chown www-data:www-data /var/www/html
WORKDIR /var/www/html
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint
ENTRYPOINT ["/sbin/tini", "--", "entrypoint"]
CMD ["php-fpm"]

# --- Production application image ------------------------------------------
FROM base AS app
COPY docker/php/prod.ini /usr/local/etc/php/conf.d/zz-prod.ini
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
RUN rm -rf tests docs docker .git .claude .cursor .agents node_modules \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && php artisan package:discover --ansi
USER www-data
EXPOSE 9000

# --- Production web server (static public/ + FastCGI to the app) ------------
FROM nginx:1.27-alpine AS nginx
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=app /var/www/html/public /var/www/html/public
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD wget -qO- http://127.0.0.1/up >/dev/null || exit 1

# --- Development image ------------------------------------------------------
FROM base AS dev
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY docker/php/dev.ini /usr/local/etc/php/conf.d/zz-dev.ini
RUN apk add --no-cache git unzip
ENV APP_ENV=local COMPOSER_ALLOW_SUPERUSER=1
EXPOSE 9000
