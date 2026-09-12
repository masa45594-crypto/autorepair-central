FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libzip-dev unzip ca-certificates && docker-php-ext-install mysqli zip && rm -rf /var/lib/apt/lists/*
WORKDIR /site
CMD ["php", "-S", "0.0.0.0:8080", "-t", "/site"]
