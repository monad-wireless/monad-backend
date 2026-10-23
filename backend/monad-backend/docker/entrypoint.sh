#!/bin/sh
# Container entrypoint: bring the instance to a serviceable state, then hand off to PHP-FPM.
#
# Each step is idempotent and each failure is loud. A container that starts but cannot reach its
# database, or that serves with a stale cache, is worse than one that refuses to start — the first
# produces confusing 500s under load, the second produces one clear line in `docker logs`.
set -eu

echo "[entrypoint] APP_ENV=${APP_ENV:-prod} OTEL_SERVICE_NAME=${OTEL_SERVICE_NAME:-unset}"

# JWT keypair. Generated on first boot into a named volume so tokens survive redeploys — a fresh
# keypair per deploy would invalidate every phone's session in the field.
if [ ! -f config/jwt/private.pem ]; then
    echo "[entrypoint] generating JWT keypair (first boot)"
    php bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction
fi
# On every boot, not only the first: PHP-FPM workers run as www-data, and a keypair generated
# while the server ran as root (FrankenPHP, before 2026-10-22) may still be root-only in the volume.
chown -R www-data:www-data config/jwt

# Wait for Postgres. compose declares depends_on: service_healthy, but a manual `docker run`
# does not, and migrations against a not-yet-listening database fail in a way that looks like a
# schema problem rather than a timing one.
if [ -n "${DATABASE_URL:-}" ]; then
    tries=0
    until php bin/console dbal:run-sql "SELECT 1" --quiet >/dev/null 2>&1; do
        tries=$((tries + 1))
        if [ "$tries" -ge 30 ]; then
            echo "[entrypoint] database unreachable after 30 attempts" >&2
            exit 1
        fi
        sleep 2
    done
    echo "[entrypoint] database reachable"

    echo "[entrypoint] running migrations"
    php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
fi

# Bundle web assets — EasyAdmin's CSS/JS above all; without them /admin renders as unstyled HTML.
#
# Here rather than in the image for the same reason as the cache below: `assets:install` is a
# console command, so it boots the kernel, and the kernel needs DATABASE_URL and the rest of the
# runtime configuration that a build stage has no business knowing. Cheap and idempotent — it
# copies each bundle's public/ directory into ours.
php bin/console assets:install public --no-interaction

# Warm the container cache against the *runtime* environment. The image build cannot do this: it
# has no DATABASE_URL and no secrets, so a cache warmed at build time is warmed against the wrong
# configuration.
php bin/console cache:clear --no-interaction
php bin/console cache:warmup --no-interaction
chown -R www-data:www-data var

if [ -f config/lab/lab.json ]; then
    echo "[entrypoint] lab bundle present"
else
    echo "[entrypoint] WARNING: no config/lab/lab.json — /api/lab/config will serve an empty bundle"
fi

# Publish public/ to the host. FPM executes PHP and nothing else, so the host nginx serves the
# static files (bundles from assets:install above, admin.css, vendor/) from a directory it can
# read. The deploy compose bind-mounts that directory here. Copied on every boot, after
# assets:install, so an image update or a new bundle reaches the host with the container that
# ships it. Old files are removed first: a file deleted from the image must stop being served.
# No mount (development compose, a bare `docker run`) means nothing to export.
PUBLIC_EXPORT_DIR="${PUBLIC_EXPORT_DIR:-/app/public-export}"
if [ -d "$PUBLIC_EXPORT_DIR" ]; then
    find "$PUBLIC_EXPORT_DIR" -mindepth 1 -delete
    cp -a public/. "$PUBLIC_EXPORT_DIR"/
    chmod -R a+rX "$PUBLIC_EXPORT_DIR"
    echo "[entrypoint] exported public/ to $PUBLIC_EXPORT_DIR"
fi

echo "[entrypoint] starting PHP-FPM (FastCGI on :9000)"
exec php-fpm
