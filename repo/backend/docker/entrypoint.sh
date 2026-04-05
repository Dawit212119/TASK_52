#!/usr/bin/env sh

set -eu

cd /var/www

if [ -z "${APP_KEY:-}" ]; then
  export APP_KEY="base64:$(php -r "echo base64_encode(random_bytes(32));")"
  echo "[entrypoint] APP_KEY not provided; generated ephemeral key for this container runtime"
fi

mkdir -p \
  storage/app \
  storage/framework/cache \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R www-data:www-data storage bootstrap/cache || true
chmod -R ug+rwX storage bootstrap/cache || true

if [ "${WAIT_FOR_DB:-true}" = "true" ] && [ "${DB_CONNECTION:-}" != "sqlite" ]; then
  echo "[entrypoint] waiting for ${DB_CONNECTION:-database} at ${DB_HOST:-db}:${DB_PORT:-3306} ..."
  ATTEMPT=0
  MAX_ATTEMPTS="${DB_WAIT_MAX_ATTEMPTS:-60}"

  until php -r "
    try {
      \$driver = getenv('DB_CONNECTION') ?: 'mysql';
      if (\$driver === 'pgsql') {
        \$dsn = 'pgsql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
      } else {
        \$dsn = 'mysql:host=' . getenv('DB_HOST') . ';port=' . getenv('DB_PORT') . ';dbname=' . getenv('DB_DATABASE');
      }
      new PDO(\$dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));
      exit(0);
    } catch (Throwable) {
      exit(1);
    }
  "; do
    ATTEMPT=$((ATTEMPT + 1))
    if [ "$ATTEMPT" -ge "$MAX_ATTEMPTS" ]; then
      echo "[entrypoint] database is not reachable after ${MAX_ATTEMPTS} attempts"
      exit 1
    fi
    sleep 2
  done

  echo "[entrypoint] database is reachable"
fi

# Remove cached bootstrap files first: `artisan` would otherwise boot with stale DB_* (e.g. pgsql) from a persisted volume.
echo "[entrypoint] removing stale Laravel bootstrap cache from volume (before artisan)"
rm -f bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php bootstrap/cache/services.php 2>/dev/null || true
php artisan config:clear || true
php artisan cache:clear || true

php artisan package:discover --ansi || true

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
  echo "[entrypoint] running migrations"
  php artisan migrate --force
fi

if [ "${RUN_SEEDERS:-false}" = "true" ]; then
  echo "[entrypoint] running seeders"
  php artisan db:seed --force
fi

if [ "${WARMUP_CACHE:-true}" = "true" ]; then
  echo "[entrypoint] warming Laravel caches"
  # Drop stale config.php from a persisted volume so runtime DB_* from the container env always wins
  php artisan config:clear || true
  php artisan config:cache || true
  php artisan route:cache || true
  php artisan view:cache || true
fi

php artisan storage:link || true

exec "$@"
