FROM php:8.2-fpm

ARG APP_ENV
RUN echo "BUILDING FOR APP_ENV = ${APP_ENV}"

RUN apt-get update && apt-get install -y \
    libpng-dev \
    ncat \
    iproute2 \
    netcat-openbsd \
    librdkafka-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    librabbitmq-dev \
    zip \
    unzip \
    procps \
    libssh2-1-dev \
    net-tools \
    lsof \
    libfreetype6-dev \
    apt-transport-https \
    ca-certificates \
    gnupg \
    git \
    mc \
    curl \
    libpq-dev \
    rsync \
    supervisor \
    && docker-php-ext-install mbstring exif pcntl bcmath gd pdo pdo_pgsql zip sockets simplexml \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

USER root

RUN pecl install ssh2 && docker-php-ext-enable ssh2
RUN pecl install rdkafka && docker-php-ext-enable rdkafka
RUN pecl install amqp && docker-php-ext-enable amqp

# Xdebug only for test environment
RUN if [ "$APP_ENV" = "test" ]; then \
        pecl install xdebug && docker-php-ext-enable xdebug; \
    fi

WORKDIR /var/www/etl-edi-scraper

# Copy all source code first
COPY . .

# Copy mock config files
COPY ./docker/configs-data/credentials.json /var/www/etl-edi-scraper/config/credentials.json
COPY ./docker/configs-data/token.json /var/www/etl-edi-scraper/config/token.json
COPY ./docker/configs-data/sftp_config.json /var/www/etl-edi-scraper/config/sftp_config.json
COPY ./docker/configs-data/rest.json /var/www/etl-edi-scraper/config/rest.json
COPY ./docker/configs-data/rest.tokens.json /var/www/etl-edi-scraper/config/rest.tokens.json

# Copy php.ini
COPY ./docker/configs-data/php.ini /usr/local/etc/php/conf.d/custom-php.ini

# Copy supervisord config (for prod)
COPY ./config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

COPY .env.example .env

# Install dependencies AFTER copying source
RUN if [ "$APP_ENV" = "test" ]; then \
        composer update --no-interaction --prefer-dist --optimize-autoloader; \
    else \
        composer update --no-dev --no-interaction --prefer-dist --optimize-autoloader; \
    fi

# Setup permissions
RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www \
    && mkdir -p /var/www/.composer/cache \
    && chown -R www-data:www-data /var/www/.composer

# Setup supervisor directories
RUN if [ "$APP_ENV" = "prod" ]; then \
        mkdir -p /var/run/supervisor /var/log/supervisor && \
        chown -R www-data:www-data /var/run/supervisor /var/log/supervisor && \
        chmod -R 775 /var/run/supervisor /var/log/supervisor; \
    fi

EXPOSE 9003
EXPOSE 9000

CMD if [ "$APP_ENV" = "test" ]; then \
        ["php-fpm"]; \
    fi
