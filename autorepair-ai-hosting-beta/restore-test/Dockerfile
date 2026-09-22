FROM php:8.3-cli
RUN docker-php-ext-install mysqli
WORKDIR /site
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/site"]
