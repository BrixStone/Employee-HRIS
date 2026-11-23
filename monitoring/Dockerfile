FROM php:8.3-apache

# Install MySQL PDO extension and enable Apache URL rewriting
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite

WORKDIR /app

# Configure Apache to serve the HRIS project
COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
