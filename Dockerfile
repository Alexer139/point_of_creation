FROM php:8.2-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY www/ .

RUN mkdir -p /data \
    && chown www-data:www-data /data \
    && chmod 750 /data

# Point DB_PATH to the persistent volume
RUN sed -i "s|dirname(__DIR__) . '/data/database.sqlite'|'/data/database.sqlite'|g" core/db.php

RUN printf '<Files "*.sqlite">\n  Deny from all\n</Files>\n' >> .htaccess

RUN printf \
    'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\n' \
    > /usr/local/etc/php/conf.d/app.ini

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && find /var/www/html -type d -exec chmod 755 {} +

EXPOSE 80
VOLUME ["/data"]
CMD ["apache2-foreground"]
