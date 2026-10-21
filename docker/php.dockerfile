ARG PHP_VERSION=8.4
ARG NODE_VERSION=20

# Base and builder are built here from docker/base.dockerfile and docker/tests.dockerfile
# instead of pulling jez500/pricebuddy-base and -tests: those images are not rebuilt when
# PHP ships a security fix, every CI run now starts from the current php image.
FROM node:${NODE_VERSION}-bookworm-slim AS node

FROM php:${PHP_VERSION}-apache-bookworm AS base

ENV EDITOR=nano
WORKDIR /app

RUN apt update && apt install -y \
        cron \
        supervisor \
        nano \
        zip \
        unzip \
        libzip-dev \
        zlib1g-dev \
        libicu-dev \
        libpq-dev \
        netcat-traditional \
        default-mysql-client \
        less \
        && apt-get clean

RUN docker-php-ext-install exif \
    && docker-php-ext-configure pcntl --enable-pcntl \
    && docker-php-ext-install pcntl posix \
    && docker-php-ext-install mysqli pdo pdo_mysql pdo_pgsql \
    && docker-php-ext-install zip \
    && docker-php-ext-configure intl \
    && docker-php-ext-install intl

# Install composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY --from=composer/composer:2-bin /composer /usr/bin/composer

# Build vendor, required for build frontend
FROM base AS builder

# Install node
COPY --from=node /usr/lib /usr/lib
COPY --from=node /usr/local/lib /usr/local/lib
COPY --from=node /usr/local/include /usr/local/include
COPY --from=node /usr/local/bin /usr/local/bin
RUN node -v && npm -v

COPY ../.. /app

RUN mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public

RUN composer install --no-dev

RUN npm install && \
    npm run build && \
    cd docs && \
    npm install && \
    npm run docs:build

FROM base

ARG APP_VERSION=development
ENV APP_VERSION=${APP_VERSION}

COPY ../.. /app
COPY --from=builder /app/vendor /app/vendor
COPY --from=builder /app/public/build /app/public/build
COPY --from=builder /app/docs/docs/.vuepress/dist /app/public/docs

COPY ../../docker/php/php.ini /usr/local/etc/php/conf.d/zzz-php-overrides.ini
COPY ../../docker/php/schedule-cron /etc/cron.d/schedule-cron
COPY ../../docker/php/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY ../../docker/php/start-app.sh /start-app.sh
COPY ../../docker/php/start-cron.sh /start-cron.sh

# Install Apprise for notifications
RUN apt update && apt install -y apprise

RUN rm -rf /var/www/html \
    && ln -s /app/public /var/www/html \
    && a2enmod rewrite \
    && chmod 0644 /etc/cron.d/schedule-cron \
    && chmod +x /start-app.sh \
    && chmod +x /start-cron.sh \
    && touch /var/log/cron.log \
    && mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public

RUN composer install --no-dev

CMD ["/start-app.sh"]
