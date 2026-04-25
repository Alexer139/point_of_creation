FROM php:8.2-apache

# 1. Устанавливаем зависимости
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    default-mysql-client \
    libssl-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# 2. РЕШЕНИЕ ОШИБКИ MPM: 
# Принудительно оставляем только mpm_prefork (который нужен для PHP) 
# и отключаем mpm_event, который может мешать.
RUN a2dismod mpm_event || true && a2enmod mpm_prefork

# 3. Включаем rewrite и настраиваем конфиг
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

WORKDIR /var/www/html

# 4. Копируем файлы (убедись, что папка www существует в репозитории)
COPY www/ .

# 5. Настройки PHP
RUN printf 'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/app.ini

# 6. Права доступа
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && find /var/www/html -type d -exec chmod 755 {} +

# 7. Настройка порта под Railway
RUN sed -i "s/Listen 80/Listen \${PORT}/g" /etc/apache2/ports.conf \
    && sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:\${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# ВАЖНО: Никаких "service apache2 restart"! 
CMD ["apache2-foreground"]