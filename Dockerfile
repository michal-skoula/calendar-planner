# syntax=docker/dockerfile:1

# --- assets -----------------------------------------------------------------
FROM node:22-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY resources ./resources
RUN npm run build

# --- php dependencies -------------------------------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
# --no-scripts: package:discover needs a writable cache path, which only the
# runtime stage has. Discovery happens there instead.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative --no-scripts

# --- runtime ----------------------------------------------------------------
# Pinned exactly so upgrades are deliberate, as with every other stack on the
# box. Bump this tag and rebuild; `docker compose pull` does nothing here
# because the image is built locally.
FROM dunglas/frankenphp:1.13.2-php8.4.27-bookworm AS runtime

# pdo_sqlite is the whole database; intl and zip are Laravel's usual pair;
# opcache is what makes this worth running in production. curl is for the
# container healthcheck.
RUN install-php-extensions pdo_sqlite intl zip opcache \
    && apt-get update \
    && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY . .
COPY --from=assets /app/public/build ./public/build

# The SQLite file lives on a volume at /data, not in database/, so that mounting
# it cannot hide the migrations and seeders that sit there.
RUN mkdir -p /data \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache \
    && rm -f database/database.sqlite \
    && chmod -R 775 storage bootstrap/cache \
    && php artisan package:discover --ansi

ENV SERVER_NAME=":80"

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -sf http://127.0.0.1/up || exit 1
