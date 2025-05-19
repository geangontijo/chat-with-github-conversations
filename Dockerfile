FROM php:8.4-cli
ENV COMPOSER_ALLOW_SUPERUSER=0
ENV PHP_IDE_CONFIG="serverName=local"

COPY --from=composer:1 /usr/bin/composer /usr/bin/composer

RUN apt-get update
RUN apt-get install -y libzip-dev
RUN docker-php-ext-configure pdo && docker-php-ext-install pdo && docker-php-ext-enable pdo
RUN apt-get install -y libpq-dev && docker-php-ext-configure pgsql -with-pgsql=/usr/src/php/ext/pdo_mysql/modules && docker-php-ext-install pgsql pdo_pgsql && docker-php-ext-enable pdo_pgsql
RUN docker-php-ext-configure opcache && docker-php-ext-install opcache && docker-php-ext-enable opcache
RUN docker-php-ext-configure zip && docker-php-ext-install zip && docker-php-ext-enable zip
RUN pecl install xdebug && docker-php-ext-enable xdebug
