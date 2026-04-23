FROM php:8.3-fpm-alpine

# Встановлюємо розширення для MySQL та корисні інструменти.
RUN apk add --no-cache bash icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install pdo pdo_mysql

WORKDIR /var/www/html
