FROM php:8.2-apache

RUN a2enmod rewrite

COPY endpoint.php /var/www/html/index.php

RUN mkdir -p /var/www/html/clients && \
    chmod -R 777 /var/www/html && \
    chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]
