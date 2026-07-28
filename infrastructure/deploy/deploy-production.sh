#!/usr/bin/env bash

set -Eeuo pipefail

: "${RELEASE_SHA:?RELEASE_SHA is required}"
: "${RELEASE_ARCHIVE:?RELEASE_ARCHIVE is required}"

APP_ROOT="${APP_ROOT:-/var/www/atlas}"
APP_URL="${APP_URL:-https://theclearmove.com}"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.5-fpm}"
BACKEND_ROOT="${APP_ROOT}/backend"
STAGING_ROOT="/tmp/atlas-release-${RELEASE_SHA}"
MAINTENANCE_ENABLED=0

if [[ ! "$RELEASE_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    printf 'RELEASE_SHA must be a full lowercase Git commit SHA\n' >&2
    exit 2
fi

if [[ "$APP_ROOT" != /var/www/* || "$APP_ROOT" == "/var/www/" ]]; then
    printf 'APP_ROOT must be a specific directory below /var/www\n' >&2
    exit 2
fi

cleanup() {
    rm -rf "$STAGING_ROOT"
    rm -f "$RELEASE_ARCHIVE" /tmp/deploy-production.sh
}

recover() {
    exit_code=$?

    if [[ "$MAINTENANCE_ENABLED" == "1" && -f "${BACKEND_ROOT}/artisan" ]]; then
        sudo -u www-data php "${BACKEND_ROOT}/artisan" up || true
    fi

    cleanup
    exit "$exit_code"
}

trap recover ERR
trap cleanup EXIT

test -f "$RELEASE_ARCHIVE"
rm -rf "$STAGING_ROOT"
mkdir -p "$STAGING_ROOT"
tar -xzf "$RELEASE_ARCHIVE" -C "$STAGING_ROOT"

test -f "${STAGING_ROOT}/backend/artisan"
test -f "${STAGING_ROOT}/backend/vendor/autoload.php"
test -f "${STAGING_ROOT}/backend/public/build/manifest.json"
test -f "${BACKEND_ROOT}/.env"

mkdir -p "${APP_ROOT}"

sudo -u www-data php "${BACKEND_ROOT}/artisan" down --retry=60
MAINTENANCE_ENABLED=1

rsync -a --delete \
    --exclude='backend/.env' \
    --exclude='backend/storage/' \
    --exclude='backend/public/storage' \
    "${STAGING_ROOT}/" "${APP_ROOT}/"

chown -R deploy:www-data "$APP_ROOT"
find "$APP_ROOT" -type d -exec chmod 755 {} \;
find "$APP_ROOT" -type f -exec chmod 644 {} \;
find "${BACKEND_ROOT}/storage" "${BACKEND_ROOT}/bootstrap/cache" \
    -type d -exec chmod 775 {} \;
find "${BACKEND_ROOT}/storage" "${BACKEND_ROOT}/bootstrap/cache" \
    -type f -exec chmod 664 {} \;
chmod 640 "${BACKEND_ROOT}/.env"

cd "$BACKEND_ROOT"

sudo -u www-data php artisan migrate --force
if [ ! -e "${BACKEND_ROOT}/public/storage" ] && [ ! -L "${BACKEND_ROOT}/public/storage" ]; then
    sudo -u www-data php artisan storage:link
fi
test -L "${BACKEND_ROOT}/public/storage"
test "$(readlink -f "${BACKEND_ROOT}/public/storage")" = "$(readlink -f "${BACKEND_ROOT}/storage/app/public")"
sudo -u www-data php artisan optimize
systemctl reload "$PHP_FPM_SERVICE"
sudo -u www-data php artisan queue:restart
sudo -u www-data php artisan schedule:list --no-ansi >/dev/null

sudo -u www-data php artisan up
MAINTENANCE_ENABLED=0

curl --fail --silent --show-error --retry 5 --retry-delay 3 \
    "${APP_URL}/api/ready" >/dev/null

for worker in high ai default observations maintenance; do
    supervisorctl status "atlas-worker-${worker}" | grep -q RUNNING
done

printf 'Successfully deployed %s to %s\n' "$RELEASE_SHA" "$APP_URL"
