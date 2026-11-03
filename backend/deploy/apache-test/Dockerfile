# Локальний Apache, що імітує хостинг (BE-009): mod_rewrite, .htaccess (AllowOverride All), PHP 8.4 + pdo_mysql.
# Лише для перевірки правил з deploy/public_html/.htaccess; на GoDaddy інша збірка Apache/PHP, тож остаточна перевірка — на хостингу.
FROM php:8.4-apache
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
