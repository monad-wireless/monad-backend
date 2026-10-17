#!/bin/sh
# Container entrypoint: bring the instance to a serviceable state, then hand off to FrankenPHP.
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
    chown -R www-data:www-data config/jwt
fi

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

echo "[entrypoint] starting FrankenPHP on ${SERVER_NAME:-:8000}"
exec frankenphp run --config /etc/caddy/Caddyfile
