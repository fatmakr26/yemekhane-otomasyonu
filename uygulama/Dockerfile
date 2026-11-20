FROM php:8.2-cli-alpine

RUN apk add --no-cache $PHPIZE_DEPS \
    && docker-php-ext-install pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

WORKDIR /app
COPY src/ /app/

EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "/app", "/app/index.php"]