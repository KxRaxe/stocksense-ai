#!/bin/sh
# Dev bootstrap for the Laravel container: make a fresh clone runnable.
set -e
cd /var/www/html

[ -f .env ] || { cp .env.example .env; echo "[entrypoint] created .env from .env.example"; }

if [ ! -f vendor/autoload.php ]; then
  echo "[entrypoint] installing composer dependencies"
  composer install --no-interaction --prefer-dist
fi

if ! grep -q '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi

exec "$@"
