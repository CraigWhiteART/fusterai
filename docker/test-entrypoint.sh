#!/usr/bin/env bash
#
# Prepares the container for a test run, then execs Pest with whatever arguments
# were passed to `docker compose -f docker-compose.test.yml run --rm test …`.
#
# Every step is idempotent, so repeat runs skip straight to Pest.

set -euo pipefail

cd /var/www/html

# ── Run as the mounted-volume owner, not root ────────────────────────────────
# Without this, composer and Pest write root-owned files into the bind mount.
if [ "$(id -u)" = '0' ]; then
    usermod -u "${WWWUSER:-1000}" sail 2>/dev/null || true

    # node_modules is a named volume, so it is created root-owned by Docker (and
    # stays that way if anything ran as root, e.g. `run --entrypoint bash`).
    # npm ci then fails with EACCES. Only recurses when ownership is actually wrong.
    if [ -d node_modules ] && [ "$(stat -c '%u' node_modules)" != "${WWWUSER:-1000}" ]; then
        chown -R "${WWWUSER:-1000}" node_modules
    fi

    exec gosu "${WWWUSER:-1000}" bash "$0" "$@"
fi

say() { printf '\033[0;34m==>\033[0m %s\n' "$1"; }

# ── Environment ──────────────────────────────────────────────────────────────
# .env.testing, not .env: Laravel loads it automatically when APP_ENV=testing, so
# the test stack never reads or overwrites a developer's own .env.
if [ ! -f .env.testing ]; then
    say 'Creating .env.testing'
    cp .env.example .env.testing

    sed -i 's#^APP_ENV=.*#APP_ENV=testing#' .env.testing
    sed -i 's#^DB_CONNECTION=.*#DB_CONNECTION=pgsql#' .env.testing
    sed -i 's#^DB_HOST=.*#DB_HOST=pgsql#' .env.testing
    sed -i 's#^DB_PORT=.*#DB_PORT=5432#' .env.testing
    sed -i 's#^DB_DATABASE=.*#DB_DATABASE=fusterai_test#' .env.testing
    sed -i 's#^DB_USERNAME=.*#DB_USERNAME=fusterai#' .env.testing
    sed -i 's#^DB_PASSWORD=.*#DB_PASSWORD=secret#' .env.testing
    sed -i 's#^REDIS_HOST=.*#REDIS_HOST=redis#' .env.testing
    sed -i 's#^QUEUE_CONNECTION=.*#QUEUE_CONNECTION=sync#' .env.testing
    sed -i 's#^CACHE_STORE=.*#CACHE_STORE=array#' .env.testing
    sed -i 's#^SESSION_DRIVER=.*#SESSION_DRIVER=array#' .env.testing
    sed -i 's#^SCOUT_DRIVER=.*#SCOUT_DRIVER=collection#' .env.testing
    sed -i 's#^MAIL_MAILER=.*#MAIL_MAILER=array#' .env.testing
fi

# ── Writable runtime directories ─────────────────────────────────────────────
mkdir -p \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs \
    bootstrap/cache

# ── PHP dependencies ─────────────────────────────────────────────────────────
if [ ! -f vendor/autoload.php ]; then
    say 'Installing PHP dependencies (first run — a few minutes)'
    composer install --no-interaction --prefer-dist --no-progress
fi

# ── Frontend assets ──────────────────────────────────────────────────────────
# Inertia feature tests render app.blade.php, which resolves @vite() against the
# build manifest. Without it every page-rendering test fails with
# "Vite manifest not found" — CI builds assets before running Pest for the same
# reason. Delete public/build to force a rebuild after changing a component.
if [ ! -f public/build/manifest.json ]; then
    say 'Installing Node dependencies'
    npm ci --no-audit --no-fund

    say 'Building frontend assets'
    npm run build
fi

# ── Application key ──────────────────────────────────────────────────────────
if ! grep -q '^APP_KEY=base64:' .env.testing; then
    say 'Generating application key'
    php artisan key:generate --env=testing --force --no-interaction
fi

# ── Passport signing keys ────────────────────────────────────────────────────
if [ ! -f storage/oauth-private.key ]; then
    say 'Generating Passport keys'
    php artisan passport:keys --force --no-interaction
fi

# ── Database ─────────────────────────────────────────────────────────────────
# Compose already gates on the healthcheck; this catches the gap between
# "postgres accepts connections" and "Laravel can authenticate".
say 'Waiting for database'
for _ in $(seq 1 30); do
    if php artisan db:show --env=testing >/dev/null 2>&1; then
        break
    fi
    sleep 2
done

# RefreshDatabase migrates on its own, but running it here surfaces a broken
# migration as a migration error rather than as every test failing at once.
say 'Running migrations'
php artisan migrate --env=testing --force --no-interaction

# ── Run ──────────────────────────────────────────────────────────────────────
say 'Running Pest'
exec ./vendor/bin/pest "$@"
