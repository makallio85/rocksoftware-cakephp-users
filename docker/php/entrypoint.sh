#!/bin/sh
# entrypoint.sh — runs at container start.
#
# Plugin sandbox semantics: waits for DB, runs composer install if vendor/
# is missing (deferred from build time so dev-dep installs can pull from
# private packagists without baking creds into the image), then hands off
# to supervisord.

set -e

echo "[entrypoint] Waiting for db:3306 to accept connections..."
attempts=0
until mysqladmin ping -h"${DB_HOST:-db}" --silent 2>/dev/null; do
    attempts=$((attempts + 1))
    if [ "$attempts" -ge 60 ]; then
        echo "[entrypoint] DB unreachable after 60s — starting anyway"
        break
    fi
    sleep 1
done
echo "[entrypoint] DB reachable after ${attempts}s"

if [ ! -d /var/www/html/vendor ]; then
    echo "[entrypoint] vendor/ missing — running composer install"
    su -s /bin/sh -c "cd /var/www/html && composer install --no-interaction --prefer-dist" www-data 2>&1 \
        || echo "[entrypoint] composer install failed — exec in to debug"
fi

if [ -f /var/www/html/bin/cake ]; then
    echo "[entrypoint] bin/cake present — running migrations"
    su -s /bin/sh -c "cd /var/www/html && bin/cake migrations migrate -p Sso" www-data 2>&1 \
        || echo "[entrypoint] Migrations failed or none yet — continuing"
fi

echo "[entrypoint] Handing off to supervisord..."
exec "$@"
