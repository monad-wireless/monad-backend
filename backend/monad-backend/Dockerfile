# PHP-FPM from the Docker Official Image (https://hub.docker.com/_/php). The container speaks
# FastCGI only; the host nginx is the HTTP server (roles/monad_api in monad-knowledge renders it):
# a loopback HTTP listener on 127.0.0.1:8084 serves public/ from disk and passes everything else
# here. Every HTTP client of the API (the public vhost, the site's /join proxy, the tailnet entry,
# monad-knowledge's quest tools) keeps calling 127.0.0.1:8084 and sees no change.
#
# Replaced FrankenPHP (dunglas/frankenphp) on 2026-10-22, by decision: only official images.
#
# The PHP patch level is pinned. 8.3.36 is the first 8.3 release that fixes CVE-2026-109618 and
# CVE-2026-109619 (php2shell); never go below it.
FROM php:8.3.36-fpm-trixie

# Extensions, built with the official image's own tooling (docker-php-ext-*, pecl) rather than a
# third-party installer:
#   pdo_pgsql, zip, intl — application requirements
#   opentelemetry        — the PECL extension the auto-instrumentation hooks into. Without it the
#                          SDK still records manually created spans, but the Symfony / Doctrine /
#                          HTTP-client instrumentation is silently absent, which is the majority
#                          of what makes a trace useful.
#   protobuf             — OTLP encodes as protobuf; the pure-PHP fallback is far slower per
#                          export, and this process exports on every request.
#   opcache              — mandatory for any PHP service under load.
# The -dev packages are needed only to compile; the runtime libraries the extensions link against
# are marked manual and kept, the -dev packages are purged. (The base image itself keeps its
# $PHPIZE_DEPS toolchain installed; that is the official image's choice, not ours.)
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends $PHPIZE_DEPS libpq-dev libzip-dev libicu-dev unzip libfcgi-bin; \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql zip intl opcache; \
    pecl install protobuf opentelemetry; \
    docker-php-ext-enable protobuf opentelemetry; \
    rm -rf /tmp/pear ~/.pearrc; \
    apt-mark auto '.*' > /dev/null; \
    apt-mark manual $savedAptMark unzip libfcgi-bin; \
    find /usr/local -type f -executable -exec ldd '{}' ';' 2>/dev/null \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u \
        | xargs -r dpkg-query --search \
        | awk 'sub(":$", "", $1) { print $1 }' \
        | sort -u \
        | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*; \
    php -m | grep -qx pdo_pgsql; php -m | grep -qx protobuf; php -m | grep -qx opentelemetry

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

# The FPM pool. The official image's default is pm.max_children = 5: five concurrent requests, so
# five phones on a slow uplink would hold every worker for up to 3000 s and stall the whole API,
# which is the failure FrankenPHP was brought in to end. 16 matches what FrankenPHP ran: on
# 2026-10-22, dunglas/frankenphp:1.13.2-php8.3.36 with 8 CPUs logged `total_threads: 16`, and the
# host is an 8-core CCX33. request_terminate_timeout is the FPM-side twin of
# max_execution_time above; it also counts time spent outside PHP, such as the S3 stream.
RUN { \
        echo "[www]"; \
        echo "pm = dynamic"; \
        echo "pm.max_children = 16"; \
        echo "pm.start_servers = 4"; \
        echo "pm.min_spare_servers = 2"; \
        echo "pm.max_spare_servers = 8"; \
        echo "pm.max_requests = 1000"; \
        echo "request_terminate_timeout = 3000s"; \
    } > /usr/local/etc/php-fpm.d/zz-monad.conf

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

# FastCGI, not HTTP. The host nginx is the only client (127.0.0.1:9084 on the host).
EXPOSE 9000

# The same /api/health request the host nginx would make, sent as FastCGI by cgi-fcgi. It proves
# FPM, the kernel and Postgres in one probe, as the old HTTP curl did; FPM's own ping would prove
# only the first. /api/health answers 503 on a database fault, which fails the grep.
HEALTHCHECK --interval=30s --timeout=5s --start-period=40s --retries=3 \
    CMD SCRIPT_FILENAME=/app/public/index.php SCRIPT_NAME=/index.php REQUEST_METHOD=GET \
        REQUEST_URI=/api/health QUERY_STRING= cgi-fcgi -bind -connect 127.0.0.1:9000 \
        | grep -q '"status":"healthy"' || exit 1

ENTRYPOINT ["/usr/local/bin/monad-entrypoint"]
