#!/bin/sh
set -eu

: "${APP_KEY:?Set a permanent Laravel APP_KEY in Render before deploying.}"
: "${DB_HOST:?Set the private MySQL host.}"
: "${DB_DATABASE:?Set the MySQL database.}"
: "${DB_USERNAME:?Set the restricted application database user.}"
: "${DB_PASSWORD:?Set the application database password.}"
export APP_URL="${APP_URL:-${RENDER_EXTERNAL_URL:-}}"
export PORT="${PORT:-10000}"
export RENDER_STORAGE_ROOT="${RENDER_STORAGE_ROOT:-/var/data}"
: "${APP_URL:?Set APP_URL or deploy as a Render web service.}"

if ! mountpoint -q "$RENDER_STORAGE_ROOT"; then
    echo 'The persistent Render disk must be mounted before starting.' >&2
    exit 1
fi

umask 027
mkdir -p "$RENDER_STORAGE_ROOT/storage/framework/cache/data" \
    "$RENDER_STORAGE_ROOT/storage/framework/sessions" \
    "$RENDER_STORAGE_ROOT/storage/framework/views" \
    "$RENDER_STORAGE_ROOT/storage/logs" \
    "$RENDER_STORAGE_ROOT/storage/app/private" \
    "$RENDER_STORAGE_ROOT/protected"
chmod 700 "$RENDER_STORAGE_ROOT/protected"
if [ ! -L /var/www/html/storage ]; then
    mv /var/www/html/storage /var/www/html/storage-image
    ln -s "$RENDER_STORAGE_ROOT/storage" /var/www/html/storage
fi
chown -R www-data:www-data "$RENDER_STORAGE_ROOT" /var/www/html/bootstrap/cache

runuser -u www-data -- php artisan config:cache --no-interaction
runuser -u www-data -- php artisan deploy:check-render --wait=120 --no-interaction
runuser -u www-data -- php artisan migrate --force --no-interaction
runuser -u www-data -- php artisan db:seed --force --no-interaction
runuser -u www-data -- php artisan view:clear --no-interaction
runuser -u www-data -- php artisan route:cache --no-interaction
exec /usr/bin/supervisord -c /var/www/html/bootstrap/render-supervisord.conf
