FROM php:8.4-cli-alpine

WORKDIR /var/www/html

# System packages + runtime dependencies for Laravel, PostgreSQL and Inertia SSR.
RUN apk add --no-cache \
    curl \
    git \
    icu-dev \
    libzip-dev \
    nodejs \
    npm \
    oniguruma-dev \
    postgresql-dev \
    unzip \
    zip

RUN docker-php-ext-install \
    bcmath \
    intl \
    mbstring \
    pcntl \
    pdo_pgsql

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY . .

RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader \
    && npm ci --no-audit --no-fund \
    && npm run build:ssr \
    && rm -rf node_modules

RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache \
    && chmod +x docker/start-web.sh docker/start-worker.sh

EXPOSE 10000

CMD ["./docker/start-web.sh"]
