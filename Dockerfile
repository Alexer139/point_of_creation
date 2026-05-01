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

WORKDIR /var/www/html

# Убедитесь, что папка www лежит в той же директории, что и Dockerfile
COPY www/ .

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

# php-fpm -D запускает PHP в фоновом режиме
# nginx запускается в основном режиме (daemon off), чтобы контейнер не закрывался
CMD php-fpm -D && nginx -g 'daemon off;'