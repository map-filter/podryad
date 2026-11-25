FROM php:8.3-fpm-alpine

RUN apk add --no-cache postgresql-dev libzip-dev icu-dev oniguruma-dev freetype-dev libpng-dev libjpeg-turbo-dev libwebp-dev linux-headers $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath pcntl opcache gd \
    && apk del $PHPIZE_DEPS linux-headers \
    && apk add --no-cache libpq libzip icu-libs freetype libpng libjpeg-turbo libwebp font-dejavu

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ARG UID=1000
ARG GID=1000
RUN sed -i "s/^pm.max_children = 5/pm.max_children = 16/; s/^pm.max_spare_servers = 3/pm.max_spare_servers = 6/" /usr/local/etc/php-fpm.d/www.conf
RUN addgroup -g ${GID} app && adduser -D -u ${UID} -G app app \
    && sed -i 's/^user = www-data/user = app/; s/^group = www-data/group = app/' /usr/local/etc/php-fpm.d/www.conf

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/backend
USER app
