#!/bin/sh
set -e
cd /var/www/backend
rm -f /tmp/app-ready
if [ ! -f vendor/autoload.php ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi
mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private
php artisan config:clear >/dev/null
php artisan migrate --force
php artisan app:seed-if-empty
touch /tmp/app-ready
exec php-fpm
