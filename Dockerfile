FROM php:7.2-apache

WORKDIR /app
RUN apt-get update && apt-get install -y wget zip unzip git
ENV APACHE_DOCUMENT_ROOT /app/public
RUN sed -i 's/80/8080/g' /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf
EXPOSE 8080
RUN useradd -ms /bin/bash mum
COPY . /app
RUN chown -R mum:mum /app
RUN chmod +x /app/docker/install_composer.sh && /app/docker/install_composer.sh
USER mum
RUN composer install
