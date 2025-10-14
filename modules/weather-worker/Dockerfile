FROM php:8.3-alpine AS base

RUN apk update && apk upgrade

# Extensions PHP
# https://github.com/mlocati/docker-php-extension-installer
RUN curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o - | sh -s \
      sockets xdebug

# Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app/modules/worker

COPY ./modules/weather-worker /app/modules/worker
COPY ./packages /app/packages

RUN rm -rf /app/modules/worker/vendor

HEALTHCHECK CMD php src/healthcheck.php

FROM base AS worker_dev

RUN apk add --no-cache watchexec
RUN composer install --no-ansi --no-cache --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader

CMD ["sh", "-c", "\
[ ! -d vendor ] && composer install \
  --no-ansi --no-cache --no-interaction \
  --no-plugins --no-progress --no-scripts \
  --optimize-autoloader || true; \
watchexec \
  --watch src \
  --exts php --restart \
  --stop-timeout 1s \
  -- php src/index.php"]

FROM base AS worker_prod

RUN composer install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader
RUN rm /usr/local/bin/composer

CMD ["php", "src/index.php"]