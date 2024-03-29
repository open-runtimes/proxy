# Install PHP libraries
FROM composer:2.0 as composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Prepare generic compiler
FROM php:8.0.18-cli-alpine3.15 as compile

ENV PHP_SWOOLE_VERSION=v4.8.10

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  openssl-dev
  
RUN docker-php-ext-install sockets

# Compile Swoole
FROM compile AS swoole

RUN \
  git clone --depth 1 --branch $PHP_SWOOLE_VERSION https://github.com/swoole/swoole-src.git && \
  cd swoole-src && \
  phpize && \
  ./configure --enable-sockets --enable-http2 --enable-openssl && \
  make && make install && \
  cd ..

# Proxy
FROM php:8.0.18-cli-alpine3.15 as final

ARG OPR_PROXY_VERSION
ENV OPR_PROXY_VERSION=$OPR_PROXY_VERSION

LABEL maintainer="team@appwrite.io"

RUN \
  apk update \
  && apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  curl-dev \
  && apk add --no-cache \
  libstdc++ \
  && docker-php-ext-install sockets \
  && apk del .deps \
  && rm -rf /var/cache/apk/*

WORKDIR /usr/local/

# Source code
COPY ./app /usr/local/app
COPY ./src /usr/local/src

# Extensions and libraries
COPY --from=composer /usr/local/src/vendor /usr/local/vendor
COPY --from=swoole /usr/local/lib/php/extensions/no-debug-non-zts-20200930/swoole.so /usr/local/lib/php/extensions/no-debug-non-zts-20200930/

RUN echo extension=swoole.so >> /usr/local/etc/php/conf.d/swoole.ini

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -s -H "Authorization: Bearer ${OPR_PROXY_SECRET}" --fail http://127.0.0.1:80/v1/proxy/health

CMD [ "php", "app/http.php" ]