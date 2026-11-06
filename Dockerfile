FROM node:22-alpine AS frontend
WORKDIR /ui
COPY frontend/package*.json ./
RUN npm ci
COPY frontend/ ./
RUN npm run build

FROM php:8.4-fpm-alpine AS app
RUN apk add --no-cache sqlite-dev oniguruma-dev libxml2-dev icu-dev libzip-dev git unzip linux-headers && docker-php-ext-install pdo_sqlite mbstring dom xml xmlwriter intl zip pcntl
RUN apk add --no-cache su-exec
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY backend/ ./
COPY contracts/ /var/www/contracts/
RUN composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
COPY --from=frontend /ui/dist ./public/app
RUN mkdir -p /data/backups && chown -R www-data:www-data storage bootstrap/cache /data
COPY docker-entrypoint.sh /usr/local/bin/workblocks-start
RUN chmod +x /usr/local/bin/workblocks-start
ENTRYPOINT ["workblocks-start"]
CMD ["php-fpm"]

FROM caddy:2-alpine AS web
COPY Caddyfile /etc/caddy/Caddyfile
COPY --from=app /var/www/html/public /var/www/html/public
