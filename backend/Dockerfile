FROM php:8.4-cli

# mbstring, json, openssl, sodium уже входять в офіційний образ; додаємо лише драйвер MySQL
RUN docker-php-ext-install pdo_mysql

WORKDIR /app
