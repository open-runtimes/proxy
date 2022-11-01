FROM composer:2.0 as composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist
    
FROM php:8.1.8-cli-alpine3.15

ENV OPEN_RUNTIMES_PROXY_VERSION=0.1.0

# Source: https://github.com/swoole/docker-swoole/blob/master/dockerfiles/5.0.0/php8.1/alpine/Dockerfile
RUN \
    curl -sfL https://getcomposer.org/installer | php -- --install-dir=/usr/bin --filename=composer && \
    chmod +x /usr/bin/composer                                                                     && \
    composer self-update --clean-backups 2.3.10                                    && \
    apk update && \
    apk add --no-cache libstdc++ libpq && \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev postgresql-dev openssl-dev pcre-dev pcre2-dev zlib-dev && \
    docker-php-ext-install sockets && \
    docker-php-source extract && \
    mkdir /usr/src/php/ext/swoole && \
    curl -sfL https://github.com/swoole/swoole-src/archive/v5.0.0.tar.gz -o swoole.tar.gz && \
    tar xfz swoole.tar.gz --strip-components=1 -C /usr/src/php/ext/swoole && \
    docker-php-ext-configure swoole \
        --enable-mysqlnd      \
        --enable-swoole-pgsql \
        --enable-openssl      \
        --enable-sockets --enable-swoole-curl && \
    docker-php-ext-install -j$(nproc) swoole && \
    rm -f swoole.tar.gz $HOME/.composer/*-old.phar && \
    docker-php-source delete && \
    apk del .build-deps

WORKDIR /usr/local/

# Add Source Code
COPY ./app /usr/local/app
COPY ./src /usr/local/src
COPY ./tests /usr/local/tests

COPY --from=composer /usr/local/src/vendor /usr/local/vendor

# For running tests in Docker container
COPY composer.json /usr/local/composer.json
COPY phpunit.xml /usr/local/phpunit.xml

EXPOSE 80

CMD [ "php", "app/http.php" ]