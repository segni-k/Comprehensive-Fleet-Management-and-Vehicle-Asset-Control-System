FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libzip-dev unzip \
    && docker-php-ext-install intl pdo_mysql pcntl zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.10 /usr/bin/composer /usr/bin/composer
WORKDIR /workspace/apps/api
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY apps/api ./
RUN composer dump-autoload --optimize

EXPOSE 8080
