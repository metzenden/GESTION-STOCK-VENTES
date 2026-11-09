# Image PHP-FPM commune à app, queue et scheduler.
FROM php:8.4-fpm-bookworm AS base

COPY --from=mlocati/php-extension-installer:2.7 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/local/bin/composer

RUN apt-get update \
    && apt-get install -y --no-install-recommends poppler-utils unzip git default-mysql-client \
    && rm -rf /var/lib/apt/lists/* \
    && install-php-extensions pdo_mysql redis intl bcmath gd zip opcache pcntl exif

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-fds.ini

ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g ${GID} www-data

WORKDIR /var/www/backend
USER www-data

# Image de production : dépendances installées et code copié.
FROM base AS prod
COPY --chown=www-data:www-data backend/ /var/www/backend/
RUN composer install --no-dev --classmap-authoritative --no-interaction --no-progress
