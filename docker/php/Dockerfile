FROM php:8.5-fpm-bookworm

RUN pecl install xdebug \
    && docker-php-ext-enable xdebug

COPY ./docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY ./docker/php/php.ini /usr/local/etc/php/php.ini
COPY ./docker/php/xdebug.ini /usr/local/etc/php/conf.d/20-xdebug.ini

WORKDIR /var/www/html