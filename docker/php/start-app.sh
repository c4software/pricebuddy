#!/bin/bash

# Setup the environment file if it doesn't exist.
if [ ! -f ".env" ] ||  ! grep -q . ".env" ; then
    cp .env.example .env
    php artisan key:generate --force
fi

# Debugging
printenv

# Ensure storage exists
mkdir -p storage/framework/sessions \
    && mkdir -p storage/framework/views \
    && mkdir -p storage/framework/testing \
    && mkdir -p storage/logs \
    && mkdir -p storage/app/public \
    && chmod -R 777 storage

# Setup storage and clear caches
php artisan storage:link
php artisan config:clear
php artisan optimize:clear

# Check if the app key is set
if [ -z "$(php artisan config:show app.key)" ]; then
    php artisan key:generate --force
fi

# If env DB_CONNECTION is sqlite, ensure the sqlite file exists
IS_SQLITE=$(php artisan config:show database.default | grep -o 'sqlite')
if [ "$IS_SQLITE" == "sqlite" ]; then
  touch storage/database.sqlite
fi

# Run migrations and seed the database if required.
php artisan buddy:init-db

# Cache it all.
php artisan cache:clear
php artisan optimize
php artisan icons:cache
php artisan config:cache
php artisan route:cache
php artisan event:cache
php artisan buddy:regenerate-price-cache

# Start supervisor that handles cron and apache.
supervisord -c /etc/supervisor/conf.d/supervisord.conf
