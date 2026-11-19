#!/bin/sh
set -eu

: "${APP_KEY:?Set a permanent Laravel APP_KEY before deploying.}"
: "${DB_HOST:?Set the database host.}"
: "${DB_DATABASE:?Set the database name.}"
: "${DB_USERNAME:?Set the restricted application database user.}"
: "${DB_PASSWORD:?Set the application database password.}"
if [ -z "${APP_URL:-}" ] && [ -n "${RAILWAY_PUBLIC_DOMAIN:-}" ]; then
    export APP_URL="https://${RAILWAY_PUBLIC_DOMAIN}"
fi
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
export PORT="${PORT:-10000}"
export PERSISTENT_STORAGE_ROOT="${PERSISTENT_STORAGE_ROOT:-${RAILWAY_VOLUME_MOUNT_PATH:-${RENDER_STORAGE_ROOT:-/var/data}}}"
: "${APP_URL:?Set the public HTTPS APP_URL.}"

if ! mountpoint -q "$PERSISTENT_STORAGE_ROOT"; then
    echo 'Persistent application storage must be mounted before starting.' >&2
    exit 1
fi

umask 027
mkdir -p "$PERSISTENT_STORAGE_ROOT/storage/framework/cache/data" \
    "$PERSISTENT_STORAGE_ROOT/storage/framework/sessions" \
    "$PERSISTENT_STORAGE_ROOT/storage/framework/views" \
    "$PERSISTENT_STORAGE_ROOT/storage/logs" \
    "$PERSISTENT_STORAGE_ROOT/storage/app/private" \
    "$PERSISTENT_STORAGE_ROOT/protected"
chmod 700 "$PERSISTENT_STORAGE_ROOT/protected"
if [ ! -L /var/www/html/storage ]; then
    mv /var/www/html/storage /var/www/html/storage-image
    ln -s "$PERSISTENT_STORAGE_ROOT/storage" /var/www/html/storage
fi
chown -R www-data:www-data "$PERSISTENT_STORAGE_ROOT" /var/www/html/bootstrap/cache

runuser -u www-data -- php artisan config:cache --no-interaction
runuser -u www-data -- php artisan deploy:check --wait=120 --no-interaction
if [ "${DB_CONNECTION:-mysql}" = "pgsql" ]; then
    runuser -u www-data -- php artisan supabase:prepare --no-interaction
else
    runuser -u www-data -- php artisan migrate --force --no-interaction
    runuser -u www-data -- php artisan db:seed --force --no-interaction
fi
runuser -u www-data -- php artisan view:clear --no-interaction
runuser -u www-data -- php artisan route:cache --no-interaction
exec /usr/bin/supervisord -c /var/www/html/bootstrap/render-supervisord.conf
