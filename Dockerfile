FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    poppler-utils \
    libzip-dev \
    unzip \
    && docker-php-ext-install zip \
    && rm -rf /var/lib/apt/lists/*

RUN echo "upload_max_filesize = 100M"  >> /usr/local/etc/php/conf.d/app.ini \
 && echo "post_max_size = 110M"        >> /usr/local/etc/php/conf.d/app.ini \
 && echo "memory_limit = 512M"         >> /usr/local/etc/php/conf.d/app.ini \
 && echo "max_execution_time = 0"      >> /usr/local/etc/php/conf.d/app.ini \
 && echo "max_input_time = 0"          >> /usr/local/etc/php/conf.d/app.ini

COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html \
 && mkdir -p /tmp/demo_ia_sessions \
 && chmod 777 /tmp/demo_ia_sessions

EXPOSE 80
