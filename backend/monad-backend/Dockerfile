# FrankenPHP: a production HTTP server with PHP embedded — one process, no FPM/nginx pair.
#
# This replaces `symfony server:start`, which the previous image ran in production. That is a
# development server, unsupported for production by its own documentation, and single-request
# oriented: one slow object-storage upload stalled every other request on the box.
FROM dunglas/frankenphp:1.9-php8.3

# Extensions:
#   pdo_pgsql, zip, intl — application requirements
#   opentelemetry        — the PECL extension the auto-instrumentation hooks into. Without it the
#                          SDK still records manually created spans, but the Symfony / Doctrine /
#                          HTTP-client instrumentation is silently absent, which is the majority
#                          of what makes a trace useful.
#   protobuf             — OTLP encodes as protobuf; the pure-PHP fallback is far slower per
#                          export, and this process exports on every request.
#   opcache              — mandatory for any PHP service under load.
RUN install-php-extensions \
        pdo_pgsql \
        zip \
        intl \
        opcache \
        protobuf \
        opentelemetry

# Upload limits mirror S3Service::MAX_FILE_SIZE (500 MB, raised 2026-08-20 for large LiDAR
# mesh.ply / ARWorldMap artefacts). If the PHP limit were lower, an oversized session artefact
# would be rejected by the runtime with an opaque error before the service's own validation could
# produce a useful one. memory_limit carries headroom above the file size for multipart parsing;
# max_execution_time tracks the nginx proxy_read_timeout / proxy_send_timeout in the vhost so PHP
# is never the shorter clock on a slow upload.
RUN { \
        echo "upload_max_filesize = 500M"; \
        echo "post_max_size = 520M"; \
        echo "memory_limit = 1024M"; \
        echo "max_execution_time = 3000"; \
        echo "opcache.enable = 1"; \
        echo "opcache.validate_timestamps = 0"; \
        echo "opcache.max_accelerated_files = 20000"; \
        echo "realpath_cache_size = 4096K"; \
        echo "realpath_cache_ttl = 600"; \
    } > /usr/local/etc/php/conf.d/monad.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

ENV APP_ENV=prod \
    APP_DEBUG=0 \
    # JWT key paths default to the volume the entrypoint generates into, so the image runs
    # standalone; compose may still override them.
    JWT_SECRET_KEY=/app/config/jwt/private.pem \
    JWT_PUBLIC_KEY=/app/config/jwt/public.pem \
    # Router base for absolute URLs generated outside an HTTP request (console, API docs).
    DEFAULT_URI=https://api.monad.dubec.dev \
    CORS_ALLOW_ORIGIN='^https://(api\.)?monad\.dubec\.dev$' \
    # FrankenPHP serves plain HTTP on 8000; TLS is terminated by the host nginx in front of it, so
    # the container must not try to provision a certificate of its own.
    SERVER_NAME=:8000 \
    # OpenTelemetry. The SDK bootstraps from composer autoload when this is on; the endpoint is
    # Alloy on the shared `monad` docker network (IP-051 LGTM stack).
    OTEL_PHP_AUTOLOAD_ENABLED=true \
    OTEL_SERVICE_NAME=monad-api \
    OTEL_TRACES_EXPORTER=otlp \
    OTEL_METRICS_EXPORTER=otlp \
    OTEL_LOGS_EXPORTER=none \
    OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf \
    OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318 \
    OTEL_PROPAGATORS=tracecontext,baggage

# Dependencies first, so a source-only change does not re-resolve the whole tree.
COPY composer.json composer.lock symfony.lock ./
RUN composer install --no-interaction --no-dev --no-scripts --no-autoloader --prefer-dist

COPY . .

# Symfony's runtime requires a .env file to exist even when every value comes from the process
# environment — Dotenv throws on a missing path before the kernel boots. The real repository .env
# is (correctly) gitignored, so the image supplies a minimal one. Values here are defaults only;
# compose/`docker run -e` override them.
RUN printf 'APP_ENV=prod\nAPP_DEBUG=0\n' > .env

RUN composer dump-autoload --optimize --no-dev --classmap-authoritative

# Bundle assets (EasyAdmin's CSS/JS, API Platform's, NelmioApiDoc's) are installed by the
# ENTRYPOINT, not here. `composer install` above runs --no-scripts so the auto-scripts hook never
# fires, and /public/bundles/ is gitignored so the COPY brought nothing — but `assets:install` is
# a console command, and booting the kernel needs the runtime configuration this stage does not
# have (it failed on DATABASE_URL). Same reason the cache is warmed at boot rather than built in.

# var/ must be writable by the server and must not carry a build-time cache generated against
# different environment values.
RUN rm -rf var/cache/* var/log/* \
    && mkdir -p var/cache var/log config/jwt config/lab \
    && chown -R www-data:www-data var config/jwt

COPY docker/entrypoint.sh /usr/local/bin/monad-entrypoint
RUN chmod +x /usr/local/bin/monad-entrypoint

EXPOSE 8000

HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD curl -fsS http://127.0.0.1:8000/api/health || exit 1

ENTRYPOINT ["/usr/local/bin/monad-entrypoint"]
