# Production image: PHP-FPM 8.3 + built assets. Pair with docker-compose.yml (Caddy, MySQL, Redis, queue, scheduler).
FROM node:22-alpine AS assets
WORKDIR /app
COPY package*.json vite.config.js ./
COPY resources ./resources
COPY app/Filament ./app/Filament
RUN npm ci && npm run build

FROM php:8.3-fpm-alpine
RUN apk add --no-cache icu-dev libzip-dev libpng-dev libjpeg-turbo-dev freetype-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) intl zip gd pdo_mysql bcmath opcache pcntl \
    && apk add --no-cache --virtual .build $PHPIZE_DEPS && pecl install redis && docker-php-ext-enable redis && apk del .build
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer install --no-dev --optimize-autoloader --no-interaction \
    && php artisan filament:assets \
    && chown -R www-data:www-data storage bootstrap/cache
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-luxe.ini
USER www-data
CMD ["php-fpm"]
