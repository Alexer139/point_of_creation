FROM php:8.2-fpm

# Устанавливаем системные зависимости и расширения PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
    nginx \
    libpng-dev \
    default-mysql-client \
    libssl-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Копируем конфиг Nginx (мы создадим его следующим шагом)
COPY nginx.conf /etc/nginx/sites-available/default

WORKDIR /var/www/html

# Копируем файлы проекта
COPY www/ .

# Настройки прав
RUN chown -R www-data:www-data /var/www/html

# Railway прокидывает порт через переменную PORT. 
# Мы подставим его в конфиг Nginx перед запуском.
CMD sed -i "s/8080/$PORT/g" /etc/nginx/sites-available/default && \
    php-fpm -D && \
    nginx -g 'daemon off;'