FROM php:8.4-cli-alpine

RUN apk add --no-cache \
        git \
        icu-dev \
        libzip-dev \
        $PHPIZE_DEPS \
        sqlite-dev \
        unzip \
        zip \
    && pecl install redis \
    && docker-php-ext-install \
        intl \
        opcache \
        zip \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
