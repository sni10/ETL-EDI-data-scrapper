# Используем PHP 8.2 FPM
FROM php:8.2-fpm

ARG ENVIRONMENT
RUN echo "BUILDING FOR ENVIRONMENT = ${ENVIRONMENT}"

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
    && docker-php-ext-install mbstring exif pcntl bcmath gd pdo pdo_pgsql zip sockets simplexml

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

USER root


RUN pecl install ssh2 && docker-php-ext-enable ssh2
RUN pecl install xdebug && docker-php-ext-enable xdebug
RUN pecl install rdkafka && docker-php-ext-enable rdkafka
RUN pecl install amqp  && docker-php-ext-enable amqp

# Копируем файлы окружения

COPY ./docker/config-envs/${ENVIRONMENT}/php.ini /usr/local/etc/php/conf.d/custom-php.ini
COPY ./config/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Основной код
WORKDIR /var/www/etl-edi-scraper
COPY . .

# Копируем моковые конфигурационные файлы (будут заменены реальными данными в runtime)
COPY ./docker/configs-data/credentials.json /var/www/etl-edi-scraper/config/credentials.json
COPY ./docker/configs-data/token.json /var/www/etl-edi-scraper/config/token.json
COPY ./docker/configs-data/sftp_config.json /var/www/etl-edi-scraper/config/sftp_config.json
COPY ./docker/configs-data/rest.json /var/www/etl-edi-scraper/config/rest.json
COPY ./docker/configs-data/rest.tokens.json /var/www/etl-edi-scraper/config/rest.tokens.json

COPY ./docker/config-envs/${ENVIRONMENT}/.env.${ENVIRONMENT} /var/www/etl-edi-scraper/.env

# Настройка прав
RUN chown -R www-data:www-data /var/www \
    && chmod -R 775 /var/www \
    && mkdir -p /var/www/.composer/cache \
    && chown -R www-data:www-data /var/www/.composer

# Настройка supervisor
RUN mkdir -p /var/run/supervisor /var/log/supervisor && \
    chown -R www-data:www-data /var/run/supervisor /var/log/supervisor && \
    chmod -R 775 /var/run/supervisor /var/log/supervisor

EXPOSE 9003
EXPOSE 9000
