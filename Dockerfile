# syntax=docker/dockerfile:1

############################
# 1) PHP + vendor + source #
############################
FROM php:8.3-cli-bookworm AS base

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libzip-dev libicu-dev libpng-dev libjpeg-dev libfreetype6-dev libonig-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip intl gd pcntl bcmath opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock* ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .
RUN composer dump-autoload --optimize --no-interaction \
    && php artisan package:discover --ansi

################################
# 2) Front-end assets (Vite)   #
################################
FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY --from=base /var/www/html /app
RUN npm install --no-audit --no-fund && npm run build

#########################
# 3) Final runtime image #
#########################
FROM base AS final
COPY --from=assets /app/public/build /var/www/html/public/build

# .dockerignore keeps the real .env out of the image (config comes from docker-compose env_file).
# `php artisan test` still tries to read /var/www/html/.env and emits a warning per test when it is
# missing, so create an EMPTY one. It holds no values: real environment variables still win.
RUN touch .env

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chmod -R ug+rwx storage bootstrap/cache

ENV PHP_CLI_SERVER_WORKERS=4
EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
# Dev-grade built-in server. NOTE: `artisan serve` is intentionally not used - it strips
# environment variables, so the container's real env would not reach the app.
# The router script resolves index.php from the *current working directory* (getcwd()), and the
# entrypoint cds to the project root, so start the server from public/ (what `artisan serve` does).
CMD ["sh", "-c", "cd public && exec php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"]
