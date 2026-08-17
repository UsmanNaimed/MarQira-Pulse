# syntax=docker/dockerfile:1
# The syntax directive above enables BuildKit features used below
# (RUN --mount=type=cache). Coolify builds with BuildKit, so this is honored.

FROM php:8.3-fpm-alpine AS base

# Install system dependencies
RUN apk add --no-cache \
    git curl zip unzip \
    postgresql-dev \
    libzip-dev \
    oniguruma-dev \
    icu-dev \
    && docker-php-ext-install \
        pdo pdo_pgsql \
        zip \
        mbstring \
        intl \
        opcache \
        pcntl

# Install Redis extension
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Dependency layer — cached on composer.json + composer.lock ONLY.
# Copying just these two files before the application source means that an
# ordinary source-only code change does NOT invalidate this layer, so Composer
# dependencies are neither re-installed nor re-downloaded unless the lock file
# actually changes. This is the primary fix for the Coolify HTTP 429 failures.
# ---------------------------------------------------------------------------
COPY composer.json composer.lock ./

# Optional GitHub token to raise GitHub's API rate limit (60/hr -> 5000/hr),
# supplied as a build argument (e.g. Coolify build arg GITHUB_TOKEN).
# It is written into a throwaway COMPOSER_HOME that is removed inside the same
# RUN layer, so the token is NEVER persisted into any image layer.
ARG GITHUB_TOKEN

# Install production dependencies.
#   --mount=type=cache keeps Composer's download cache on the BuildKit cache
#     volume (never part of the image), so even when this layer must rebuild,
#     packages are served from the local cache instead of being re-downloaded
#     from GitHub/codeload — this is what avoids the HTTP 429 throttling.
#   COMPOSER_MAX_PARALLEL_HTTP=4 lowers burst concurrency toward GitHub.
#   Scripts and autoloader are skipped here because the application source is
#   not present yet; the optimized autoloader + package discovery run below.
RUN --mount=type=cache,target=/tmp/composer-cache \
    set -eu; \
    export COMPOSER_HOME=/tmp/composer-home; \
    export COMPOSER_CACHE_DIR=/tmp/composer-cache; \
    export COMPOSER_MAX_PARALLEL_HTTP=4; \
    if [ -n "${GITHUB_TOKEN:-}" ]; then \
        composer config --global github-oauth.github.com "${GITHUB_TOKEN}"; \
    fi; \
    composer install \
        --no-dev \
        --prefer-dist \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --no-autoloader; \
    rm -rf "${COMPOSER_HOME}"

# Copy application source. This layer changes on every code change, but the
# dependency layer above stays cached as long as the lock file is unchanged.
COPY . .

# Generate the optimized autoloader (this triggers Laravel package discovery
# via the post-autoload-dump script) and cache framework config/routes/views
# for production. No packages are downloaded here, but the cache mount is kept
# so any transient Composer network access is still served from cache.
RUN --mount=type=cache,target=/tmp/composer-cache \
    set -eu; \
    export COMPOSER_HOME=/tmp/composer-home; \
    export COMPOSER_CACHE_DIR=/tmp/composer-cache; \
    composer dump-autoload --optimize --no-dev; \
    rm -rf "${COMPOSER_HOME}"; \
    php artisan config:cache; \
    php artisan route:cache; \
    php artisan view:cache

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Expose PHP-FPM port (Nginx or Caddy sits in front)
EXPOSE 9000

CMD ["php-fpm"]
