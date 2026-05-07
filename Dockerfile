FROM php:8.2-fpm

RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    libpng-dev \
    default-mysql-client \
    libssl-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

RUN rm -f /etc/nginx/sites-enabled/default

COPY nginx.conf /etc/nginx/sites-enabled/default

# Настройки PHP
RUN printf \
    'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\ndate.timezone=UTC\n' \
    > /usr/local/etc/php/conf.d/app.ini

WORKDIR /var/www/html

COPY www/ .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD php-fpm -D && nginx -g 'daemon off;'