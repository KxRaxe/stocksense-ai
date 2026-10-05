#!/bin/sh
# Production start-up for every Laravel container (web app, queue worker, scheduler, migrations).
set -e
cd /var/www/html

# The storage directory is a volume shared by these containers, so it may start out empty.
mkdir -p storage/app/private storage/app/public \
         storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs

# Refuse to start with a configuration that must not go to production (debug output on, the
# development secret, a well-known database password...). See `php artisan app:check`.
# Set SKIP_PRODUCTION_CHECK=true only for throwaway test stacks.
if [ "${SKIP_PRODUCTION_CHECK:-false}" != "true" ]; then
  php artisan app:check --strict
fi

# Compile the configuration, routes, views and events once, now that the environment is known.
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
