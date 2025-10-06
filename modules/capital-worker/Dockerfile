FROM php:8.3-alpine

RUN apk update
RUN apk upgrade

# Extensions PHP
# https://github.com/mlocati/docker-php-extension-installer
RUN curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o - | sh -s \
      sockets xdebug

WORKDIR /app/modules/worker

COPY ./modules/capital-worker /app/modules/worker
COPY ./packages /app/packages

RUN rm -rf /app/modules/worker/vendor

# Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -s https://getcomposer.org/installer | php
RUN mv composer.phar /usr/local/bin/composer
RUN alias composer='php bin/composer'
RUN composer install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader
RUN rm /usr/local/bin/composer

HEALTHCHECK CMD php src/healthcheck.php

CMD ["php", "src/index.php"]
