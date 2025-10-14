FROM php:8.3-alpine AS base

RUN apk update && apk upgrade

# Composer
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY ./modules/api-weather /app

RUN rm -rf /app/vendor

HEALTHCHECK CMD curl -f http://localhost/health || exit 1

FROM base AS api_dev

RUN composer install --no-ansi --no-cache --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader

EXPOSE 80

CMD ["sh", "-c", "\
[ ! -d vendor ] && composer install \
  --no-ansi --no-cache --no-interaction \
  --no-plugins --no-progress --no-scripts \
  --optimize-autoloader || true; \
php -S 0.0.0.0:80 -t src"]

FROM base AS api_prod

RUN composer install --no-ansi --no-dev --no-interaction --no-plugins --no-progress --no-scripts --optimize-autoloader
RUN rm /usr/local/bin/composer

EXPOSE 80

CMD ["php", "-S", "0.0.0.0:80", "-t", "src"]