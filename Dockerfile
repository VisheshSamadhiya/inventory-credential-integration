FROM composer:2 AS dependencies

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader --ignore-platform-req=ext-gd

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql zip gd \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

RUN sed -i 's/^ServerTokens OS/ServerTokens Prod/; s/^ServerSignature On/ServerSignature Off/' /etc/apache2/conf-available/security.conf \
    && printf 'TraceEnable Off\nServerName localhost\n' > /etc/apache2/conf-available/security-hardening.conf \
    && a2enconf security-hardening \
    && printf 'expose_php=Off\n' > /usr/local/etc/php/conf.d/zz-security.ini

WORKDIR /var/www/html

COPY app/ /var/www/html/
COPY --from=dependencies /app/vendor/ /var/www/html/vendor/

RUN mkdir -p /var/lib/inventory-manager/credential-attachments \
    && chown -R www-data:www-data /var/lib/inventory-manager /var/www/html

EXPOSE 80
