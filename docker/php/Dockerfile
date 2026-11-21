FROM php:8.4-cli-alpine

RUN apk add --no-cache \
git \
unzip \
postgresql-dev \
$PHPIZE_DEPS \
&& docker-php-ext-install pdo pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json ./
RUN composer install --no-interaction --no-dev --prefer-dist --no-scripts

COPY . .
RUN composer dump-autoload --optimize
