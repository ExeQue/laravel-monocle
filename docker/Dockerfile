ARG PHP=8.4
FROM php:${PHP}-cli-alpine

RUN apk update && apk add \
    zip libzip-dev icu-dev git libpng-dev libjpeg-turbo-dev freetype-dev

RUN docker-php-ext-configure gd --with-jpeg --with-freetype && docker-php-ext-install zip intl exif gd

RUN apk add --no-cache linux-headers autoconf build-base
RUN pecl install xdebug

RUN docker-php-ext-enable xdebug
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

USER "1000:1000"

ENTRYPOINT ["php"]
