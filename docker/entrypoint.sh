#!/bin/sh
set -e

cd /var/www/html

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views \
         storage/logs storage/app/private bootstrap/cache

if [ -z "$APP_KEY" ]; then
  echo "APP_KEY is empty. Run ./docker/setup.sh first (it creates .env)." >&2
  exit 1
fi

# Only the web container migrates/seeds (RUN_MIGRATIONS=true) so that the queue
# worker and scheduler never race it.
if [ "$RUN_MIGRATIONS" = "true" ]; then
  php artisan migrate --force
  if [ "$RUN_SEEDERS" = "true" ]; then
    php artisan db:seed --force
  fi
fi

exec "$@"
