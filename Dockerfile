FROM php:8.4-cli-alpine

RUN apk add --no-cache \
    bash \
    curl \
    freetype-dev \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libzip-dev \
    oniguruma-dev \
    unzip \
    zip \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j$(nproc) \
    bcmath \
    gd \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo_mysql \
    zip

# Enable OPcache for CLI server & multi-worker PHP server so parallel API calls don't serialize
RUN printf "opcache.enable=1\nopcache.enable_cli=1\nopcache.memory_consumption=128\nopcache.interned_strings_buffer=16\nopcache.max_accelerated_files=10000\nopcache.validate_timestamps=0\n" \
  > /usr/local/etc/php/conf.d/opcache-production.ini

ENV PHP_CLI_SERVER_WORKERS=6

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev \
  && chmod +x docker/entrypoint.sh \
  && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
  && chmod -R 775 storage bootstrap/cache

EXPOSE 8000

ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
CMD ["sh", "-c", "php artisan serve --host=0.0.0.0 --port=${PORT:-8000}"]
