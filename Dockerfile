FROM php:8.2-apache

# Установка системных зависимостей и расширений PHP
RUN apt-get update && apt-get install -y --no-install-recommends \
        libsqlite3-dev sqlite3 \
    && docker-php-ext-install pdo pdo_sqlite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Включаем mod_rewrite для Apache (нужен для красивых ссылок и .htaccess)
RUN a2enmod rewrite \
    && sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Указываем рабочую директорию
WORKDIR /var/www/html

# Копируем содержимое вашей папки www в контейнер
COPY www/ .

# Создаем папку для базы данных и настраиваем права
RUN mkdir -p /data \
    && chown www-data:www-data /data \
    && chmod 750 /data

# Правим путь к БД в конфиге (если файл core/db.php существует)
RUN if [ -f core/db.php ]; then \
    sed -i "s|dirname(__DIR__) . '/data/database.sqlite'|'/data/database.sqlite'|g" core/db.php; \
    fi

# Запрещаем доступ к sqlite файлам через браузер
RUN printf '<Files "*.sqlite">\n  Deny from all\n</Files>\n' >> .htaccess

# Оптимизируем настройки PHP
RUN printf \
    'upload_max_filesize=16M\npost_max_size=16M\nmemory_limit=128M\nsession.cookie_httponly=1\nexpose_php=Off\n' \
    > /usr/local/etc/php/conf.d/app.ini

# Настраиваем права доступа на файлы сайта
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && find /var/www/html -type d -exec chmod 755 {} +

EXPOSE 80

# СТРОКА VOLUME УДАЛЕНА, ТАК КАК RAILWAY ЕЕ НЕ ПРИНИМАЕТ
# Если нужны постоянные данные, они настраиваются в панели Railway

CMD ["apache2-foreground"]