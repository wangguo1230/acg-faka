# docker-deploy 保持 Apache 与现有多卷升级模型；PHP 版本默认使用仍受支持的 8.2。
ARG PHP_VERSION=8.2
FROM php:${PHP_VERSION}-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html

# 打入上游 3.8.4 的 session 对象注入硬化补丁。session 是静态扩展，必须先重编
# PHP，再编译下面的动态扩展。
COPY docker/php-patches/ /usr/local/share/acg-faka/php-patches/
COPY docker/php-rebuild.sh /usr/local/bin/acg-php-rebuild
RUN sh /usr/local/bin/acg-php-rebuild /usr/local/share/acg-faka/php-patches

# 未包含 CVE-2026-109618/109619 官方修补的 PHP 基础镜像拒绝构建。
# docker-deploy 使用 Apache，不能套用上游仅针对 php-fpm/nginx 的临时缓解，因此不提供放行开关。
COPY docker/php-cve-gate.sh /usr/local/bin/acg-php-cve-gate
RUN sh /usr/local/bin/acg-php-cve-gate

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libcurl4-openssl-dev \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libonig-dev \
        libpng-dev \
        libzip-dev \
    && if ! php -m | grep -qi '^curl$'; then docker-php-ext-install curl; fi \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        mbstring \
        opcache \
        pdo_mysql \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf '%s\n' 'ServerName localhost' > /etc/apache2/conf-available/server-name.conf \
    && a2enconf server-name \
    && rm -rf /var/lib/apt/lists/*

# 每次发布刷新系统安全包，避免基础镜像/构建缓存长期保留旧修补版本。
# 本分支使用 Apache；只有实际安装了 nginx 时才执行上游的 nginx 版本闸门。
ARG SECURITY_REFRESH=
COPY docker/nginx-cve-gate.sh /usr/local/bin/acg-nginx-cve-gate
RUN set -eux; \
    echo "[acg-faka] security refresh: ${SECURITY_REFRESH:-local}"; \
    apt-get update; \
    DEBIAN_FRONTEND=noninteractive apt-get upgrade -y --no-install-recommends \
        -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold; \
    rm -rf /var/lib/apt/lists/*; \
    if command -v nginx >/dev/null 2>&1; then sh /usr/local/bin/acg-nginx-cve-gate; fi; \
    apache2ctl -t

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . /var/www/html
COPY docker/php.ini /usr/local/etc/php/conf.d/acg-faka.ini
COPY docker/entrypoint.sh /usr/local/bin/acg-faka-entrypoint
COPY docker/plugin-heal.php /usr/local/bin/acg-faka-plugin-heal.php
COPY docker/migrate.php /usr/local/bin/acg-faka-migrate.php
COPY docker/healthcheck.php /usr/local/bin/acg-faka-healthcheck.php

RUN mkdir -p \
        /usr/local/share/acg-faka \
        /var/www/html/assets/cache \
        /var/www/html/app/Pay \
        /var/www/html/app/Plugin \
        /var/www/html/app/View/User/Theme \
        /var/www/html/kernel/Install/OS \
        /var/www/html/kernel/Install/Update \
        /var/www/html/runtime/card-file \
        /var/www/html/runtime/log \
        /var/www/html/runtime/plugin \
        /var/www/html/runtime/request \
        /var/www/html/runtime/tmp \
        /var/www/html/runtime/view \
        /var/www/html/runtime/waf \
    && if [ -f /var/www/html/favicon.ico ]; then \
        cp /var/www/html/favicon.ico /usr/local/share/acg-faka/favicon.ico; \
        cp /var/www/html/favicon.ico /var/www/html/assets/cache/favicon.ico; \
        ln -sf assets/cache/favicon.ico /var/www/html/favicon.ico; \
    fi \
    && cp -a /var/www/html/app/View/User/Theme /usr/local/share/acg-faka/Theme \
    && cp -a /var/www/html/app/Plugin /usr/local/share/acg-faka/Plugin \
    && cp -a /var/www/html/config /usr/local/share/acg-faka/config \
    && if [ ! -d /var/www/html/vendor ] || [ ! -f /var/www/html/vendor/autoload.php ]; then \
        composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction; \
    else \
        composer dump-autoload --optimize --no-interaction; \
    fi \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R ug+rwX /var/www/html \
    && chmod +x /usr/local/bin/acg-faka-entrypoint

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=120s --retries=3 \
    CMD ["php", "/usr/local/bin/acg-faka-healthcheck.php"]

ENTRYPOINT ["acg-faka-entrypoint"]
CMD ["apache2-foreground"]
