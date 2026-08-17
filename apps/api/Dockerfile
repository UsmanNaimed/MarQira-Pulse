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

# Copy composer files first (layer caching)
COPY composer.json composer.lock ./

# Optional: Configure GitHub token to avoid rate limits (5000/hr vs 60/hr)
# Pass as build arg: docker build --build-arg GITHUB_TOKEN=ghp_xxx...
ARG GITHUB_TOKEN
RUN if [ -n "$GITHUB_TOKEN" ]; then \
        composer config -g github-oauth.github.com "$GITHUB_TOKEN"; \
    fi

RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# Copy application
COPY . .

RUN composer dump-autoload --optimize \
    && php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Permissions
RUN chown -R www-data:www-data storage bootstrap/cache

# Expose PHP-FPM port (Nginx or Caddy sits in front)
EXPOSE 9000

CMD ["php-fpm"]
