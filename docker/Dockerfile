# Local development image only. Production runs on Laravel Cloud.
FROM dunglas/frankenphp:1-php8.4

RUN install-php-extensions pdo_mysql bcmath intl pcntl zip opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Node lives in the PHP image because the Wayfinder Vite plugin shells out to `php artisan`.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

# Run as the host user so generated files are not root-owned.
ARG UID=1000
ARG GID=1000
RUN groupadd -g ${GID} app && useradd -u ${UID} -g app -m app \
    && chown -R app:app /data/caddy /config/caddy

USER app
WORKDIR /app
