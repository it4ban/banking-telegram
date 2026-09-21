FROM php:8.5-fpm-bookworm

# unzip/git нужны Composer для скачивания и распаковки пакетов
RUN apt-get update && apt-get install -y --no-install-recommends unzip git \
    && rm -rf /var/lib/apt/lists/*

# Composer (копируем готовый бинарник из официального образа)
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Xdebug уже входит в базовый образ php:8.5-fpm-bookworm,
# ставить/подключать повторно не нужно — настройки задаются в xdebug.ini.
RUN if ! php -m | grep -qi xdebug; then pecl install xdebug && docker-php-ext-enable xdebug; fi

COPY ./docker/php/opcache-dev.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY ./docker/php/php.ini /usr/local/etc/php/php.ini
COPY ./docker/php/xdebug.ini /usr/local/etc/php/conf.d/20-xdebug.ini

WORKDIR /var/www/html