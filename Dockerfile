FROM php:8.2-apache

# Устанавливаем зависимости
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    default-mysql-client \
    libssl-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Включаем rewrite и настраиваем конфиг
# ВАЖНО: Убираем команду restart! Apache сам подхватит настройки при старте контейнера
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Копируем файлы
COPY www/ .

# Настройки PHP
RUN printf 'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/app.ini

# Права доступа
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && find /var/www/html -type d -exec chmod 755 {} +

# Railway ожидает, что Apache будет слушать порт, который он выдаст (переменная PORT)
# По умолчанию Apache слушает 80, но для надежности добавим:
RUN sed -i "s/Listen 80/Listen \${PORT}/g" /etc/apache2/ports.conf
RUN sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:\${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Запуск Apache в правильном режиме
CMD ["apache2-foreground"]