# syntax=docker/dockerfile:1.7

# ---------------------------------------------------------------------------
# base: php-fpm 8.4 + extensions + composer + node (Wayfinder's Vite plugin
# shells out to `php artisan`, so both runtimes live in one image)
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS base

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl postgresql-client \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions pdo_pgsql pgsql redis intl gd zip bcmath opcache pcntl exif

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-stocksense.ini

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# dev: source is bind-mounted, runs as an unprivileged user matching host UID
# ---------------------------------------------------------------------------
FROM base AS dev
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app 2>/dev/null || true \
 && useradd -m -u ${UID} -g ${GID} -s /bin/bash app 2>/dev/null || true \
 && mkdir -p /var/www/html/node_modules /home/app/.npm \
 && chown -R ${UID}:${GID} /var/www/html /home/app/.npm
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY --chmod=755 docker/php/vite-dev.sh /usr/local/bin/vite-dev.sh
USER ${UID}
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# vendor: the application with its production dependencies only
# ---------------------------------------------------------------------------
FROM base AS vendor
WORKDIR /app
COPY web/composer.json web/composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader
COPY web/ ./
# The scripts run `artisan`, which needs a key to start; this one is thrown away with the stage.
RUN APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    composer dump-autoload --no-dev --optimize --classmap-authoritative \
 && rm -rf /root/.composer

# ---------------------------------------------------------------------------
# assets: the React app built with Vite. The build calls `php artisan` (Wayfinder turns the
# routes into TypeScript), so it runs where PHP and the vendor directory are.
# ---------------------------------------------------------------------------
FROM base AS assets
WORKDIR /app
# The packages first: they change far less often than the code, and installing them is the slow part.
COPY web/package.json web/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app/ ./
ENV APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_ENV=production \
    CACHE_STORE=array \
    SESSION_DRIVER=array \
    QUEUE_CONNECTION=sync
RUN npm run build \
 && rm -rf node_modules

# ---------------------------------------------------------------------------
# prod: what runs in production. PHP-FPM without Node, Composer or git, as an
# unprivileged user, with the code and the built assets inside the image.
# ---------------------------------------------------------------------------
FROM php:8.4-fpm-bookworm AS prod

COPY --from=ghcr.io/mlocati/php-extension-installer:latest /usr/bin/install-php-extensions /usr/local/bin/
RUN apt-get update && apt-get install -y --no-install-recommends curl \
 && rm -rf /var/lib/apt/lists/* \
 && install-php-extensions pdo_pgsql pgsql redis intl gd zip bcmath opcache pcntl exif \
 && rm /usr/local/bin/install-php-extensions

COPY docker/php/php.prod.ini /usr/local/etc/php/conf.d/zz-stocksense.ini
COPY docker/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-stocksense.conf
COPY --chmod=755 docker/php/entrypoint.prod.sh /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
COPY --from=vendor --chown=www-data:www-data /app /var/www/html
COPY --from=assets --chown=www-data:www-data /app/public/build /var/www/html/public/build

RUN rm -f public/hot .env \
 && mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache

USER www-data
ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# web: nginx, with the public files (the built assets) so it serves them itself and
# hands only PHP requests to the app container
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS web
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY --from=prod /var/www/html/public /var/www/html/public
