# When Docker Hub is blocked/slow, set build-arg via Compose: DOCKER_IMAGE_REGISTRY_PREFIX (must end with /), e.g. docker.m.daocloud.io/library/
ARG REGISTRY_PREFIX=
FROM ${REGISTRY_PREFIX}composer:2 AS composer

WORKDIR /var/www

COPY composer.json ./
RUN composer install \
    --no-interaction \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM ${REGISTRY_PREFIX}php:8.4-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    curl \
    git \
    unzip \
    default-libmysqlclient-dev \
    libsqlite3-dev \
    libzip-dev \
    libicu-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    bcmath \
    exif \
    gd \
    intl \
    opcache \
    pcntl \
    pdo_mysql \
    pdo_sqlite \
    zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www

COPY --from=composer /usr/bin/composer /usr/bin/composer
COPY --from=composer /var/www/vendor /var/www/vendor
COPY . .

RUN cp .env.example .env

# Skip scripts: package:discover needs a valid APP_KEY; entrypoint runs it at container start.
RUN composer dump-autoload --no-interaction --optimize --no-scripts

RUN mkdir -p \
    storage/app \
    storage/framework/cache \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/vetops-entrypoint
RUN chmod +x /usr/local/bin/vetops-entrypoint

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/vetops-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
