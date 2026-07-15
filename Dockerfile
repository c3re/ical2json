FROM php:8-apache
RUN apt-get update && apt-get upgrade -y && apt-get install -y curl git unzip && rm -rf /var/lib/apt/lists/*
RUN rm /var/www/html/* -rf
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY htdocs/ /var/www/html
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader
RUN chown www-data:www-data /var/www/html
