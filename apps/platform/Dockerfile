# syntax=docker/dockerfile:1

# pdo_pgsql, redis and zip ship with the base image; gd and exif do not, and
# MobileApp::registerMediaConversions needs them for icon variants. Installed in
# a shared stage so the vendor build resolves the lock file's platform
# requirements against exactly the runtime the app will use — no
# --ignore-platform-req flags, no version drift between build and run.
FROM serversideup/php:8.4.23-fpm-nginx-bookworm AS php-base
USER root
RUN install-php-extensions gd exif
USER www-data

FROM php-base AS vendor
USER root
WORKDIR /app
COPY composer.json composer.lock ./
# --no-scripts: post-autoload-dump runs `artisan package:discover`, which boots
# the framework and would need a database and an APP_KEY that do not exist yet.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-autoloader \
        --prefer-dist \
        --no-interaction \
        --no-progress
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts
COPY . .
RUN npm run build

FROM php-base AS app

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    HEALTHCHECK_PATH=/up \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false

WORKDIR /var/www/html

COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor --chown=www-data:www-data /app/vendor /var/www/html/vendor
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

USER root
COPY docker/app-manager /usr/local/bin/app-manager
COPY docker/entrypoint.d/60-app-manager.sh /etc/entrypoint.d/60-app-manager.sh
RUN chmod +x /usr/local/bin/app-manager /etc/entrypoint.d/60-app-manager.sh
USER www-data
