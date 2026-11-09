FROM node:24-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM php:8.4-apache-bookworm AS runtime
RUN apt-get update && apt-get install -y --no-install-recommends libicu-dev libzip-dev unzip \
    && docker-php-ext-install -j2 pdo_mysql intl zip pcntl opcache \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction
COPY . .
COPY --from=frontend /app/public /var/www/html/public
COPY scripts/docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY scripts/docker/php.ini /usr/local/etc/php/conf.d/production.ini
COPY scripts/docker/entrypoint.sh /usr/local/bin/mcare-entrypoint
RUN sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && printf '\nServerName localhost\nServerTokens Prod\nServerSignature Off\n' >> /etc/apache2/apache2.conf \
    && mkdir -p bootstrap/cache \
    && composer dump-autoload --no-dev --optimize \
    && mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && ln -s /var/www/html/storage/app/public public/storage \
    && find app bootstrap config database public resources routes -type d -exec chmod 755 {} + \
    && chown -R www-data:www-data storage bootstrap/cache /var/run/apache2 /var/lock/apache2 \
    && chmod +x /usr/local/bin/mcare-entrypoint
USER www-data
EXPOSE 8080
ENTRYPOINT ["mcare-entrypoint"]
CMD ["apache2-foreground"]
