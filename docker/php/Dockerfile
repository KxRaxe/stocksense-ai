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
