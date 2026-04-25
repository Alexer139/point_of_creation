FROM php:8.2-apache

# Системные зависимости: MySQL клиент, PDO MySQL
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev \
        default-mysql-client \
        libssl-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Включаем mod_rewrite
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

COPY www/ .

# Оптимизируем настройки PHP
RUN printf \
    'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\n' \
    > /usr/local/etc/php/conf.d/app.ini

# Права
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && find /var/www/html -type d -exec chmod 755 {} +

EXPOSE 80

CMD ["apache2-foreground"]
