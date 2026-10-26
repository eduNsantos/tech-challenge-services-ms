FROM php:8.4-cli

WORKDIR /var/www

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        default-mysql-client \
        curl \
        libpng-dev \
        libonig-dev \
        libxml2-dev \
        zip && \
    docker-php-ext-install pdo_mysql zip && \
    rm -rf /var/lib/apt/lists/*

COPY . /var/www

RUN mkdir -p /var/www/storage/logs /var/www/bootstrap/cache \
    && chmod -R 0777 /var/www/storage /var/www/bootstrap/cache

CMD ["bash", "-lc", "php -v && tail -f /dev/null"]
