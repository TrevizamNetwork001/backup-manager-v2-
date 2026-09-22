FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install \
       pdo_pgsql \
       intl \
       mbstring \
       opcache \
       pcntl \
       zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY engine/requirements.txt /tmp/engine-requirements.txt
RUN apt-get update && apt-get install -y python3-venv \
    && rm -rf /var/lib/apt/lists/* \
    && python3 -m venv /opt/engine-venv \
    && /opt/engine-venv/bin/pip install --no-cache-dir -r /tmp/engine-requirements.txt \
    && mkdir -p /data/backups \
    && chown nobody:nogroup /data/backups
