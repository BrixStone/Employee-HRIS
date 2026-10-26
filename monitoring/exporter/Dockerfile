# HRIS metrics exporter image.
# Bakes in the pdo_mysql extension once at build time, so the container
# starts in seconds instead of installing extensions on every start.
FROM php:8.3-cli

RUN docker-php-ext-install pdo_mysql

# Match the app timezone used by the PHP pages
RUN echo "date.timezone=Asia/Manila" > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /app
EXPOSE 9091

# The project root is mounted read-only at /app by docker-compose.yml.
# router.php exposes only the metrics endpoint.
CMD ["php", "-S", "0.0.0.0:9091", "-t", "/app", "/app/monitoring/exporter/router.php"]
