FROM php:8.4-apache

COPY ./docker/apache/site-dev.conf /etc/apache2/sites-available/000-default.conf
RUN rm -f /etc/apache2/sites-enabled/000-default.conf

RUN docker-php-ext-install pdo pdo_mysql
RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli

# GD + EXIF (dom/fileinfo ship enabled by default on this base image):
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/
RUN chmod +x /usr/local/bin/install-php-extensions && sync && \
	install-php-extensions gd exif xdebug

RUN apt-get update && apt-get install -y \
		git \
		curl \
		g++ \
	&& rm -rf /var/lib/apt/lists/*

RUN a2enmod rewrite
RUN a2enmod ssl
RUN a2ensite 000-default.conf

# INSTALL COMPOSER
RUN php -r "readfile('http://getcomposer.org/installer');" | php -- --install-dir=/usr/bin/ --filename=composer
RUN alias composer='php composer.phar'
# RUN cd /var/www/html && ls && composer install --ignore-platform-reqs

EXPOSE 80
EXPOSE 8080
EXPOSE 443
