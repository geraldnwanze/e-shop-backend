#!/bin/sh
# Shared entrypoint for the app, queue and scheduler containers.
set -e
cd /var/www/html

if [ "${APP_ENV:-production}" = "local" ]; then
  # Development: bootstrap a fresh checkout without any manual steps.
  [ -f .env ] || cp .env.example .env
  [ -f vendor/autoload.php ] || composer install --no-interaction
  grep -q '^APP_KEY=.\+' .env || php artisan key:generate --no-interaction
else
  # Production: cache config/routes/events/views once per container start (env is read here).
  php artisan config:cache --no-interaction
  php artisan route:cache --no-interaction
  php artisan event:cache --no-interaction
  php artisan view:cache --no-interaction || true
fi

# Opt-in: only the web container should do this, and only on deploys that need it.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force --no-interaction
fi

exec "$@"
