FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends pure-ftpd python3 libpq-dev libonig-dev \
    && docker-php-ext-install pdo_pgsql mbstring \
    && rm -rf /var/lib/apt/lists/* \
    && mkdir -p /data/ftp/quarantine /etc/backup-ftp /ftp

COPY docker/ftp/server.py docker/ftp/admin.py /ftp/

WORKDIR /var/www/html
