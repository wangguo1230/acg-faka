FROM php:8.1-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
        libzip-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl pdo_mysql gd zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY . /var/www/html

RUN sed -ri "s/AllowOverride None/AllowOverride All/g" /etc/apache2/apache2.conf \
    && mkdir -p \
        /var/www/html/runtime \
        /var/www/html/assets/cache \
        /var/www/html/app/Plugin \
        /var/www/html/app/Pay \
        /var/www/html/app/View/User/Theme \
        /var/www/html/kernel/Install/OS \
    && touch /var/www/html/runtime.log \
    && chown -R www-data:www-data \
        /var/www/html/runtime \
        /var/www/html/assets/cache \
        /var/www/html/app/Plugin \
        /var/www/html/app/Pay \
        /var/www/html/app/View/User/Theme \
        /var/www/html/config \
        /var/www/html/kernel/Install \
        /var/www/html/runtime.log \
    && chmod -R 775 \
        /var/www/html/runtime \
        /var/www/html/assets/cache \
        /var/www/html/app/Plugin \
        /var/www/html/app/Pay \
        /var/www/html/app/View/User/Theme \
        /var/www/html/config \
        /var/www/html/kernel/Install

EXPOSE 80
