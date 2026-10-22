FROM wordpress:php8.2-apache

RUN curl -sSLo /usr/local/bin/wp https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar \
    && chmod +x /usr/local/bin/wp \
    && printf 'Listen 8081\nListen 8082\n' >> /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:80 *:8081 *:8082>/' /etc/apache2/sites-available/000-default.conf
