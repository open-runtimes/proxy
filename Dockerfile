FROM php:8.3.7-cli-alpine3.19 AS compile

ENV PHP_SWOOLE_VERSION="v5.1.2" \
    PHP_REDIS_VERSION="6.1.0"

RUN \
  apk add --no-cache --virtual .deps \
  make \
  automake \
  autoconf \
  gcc \
  g++ \
  git \
  linux-headers \
  openssl-dev \
  curl-dev
  
RUN docker-php-ext-install sockets

# Compile Redis
FROM compile AS redis
RUN \
  # Redis Extension
  git clone --depth 1 --branch $PHP_REDIS_VERSION https://github.com/phpredis/phpredis.git && \
  cd phpredis && \
  phpize && \
  ./configure && \
  make && make install

# Install PHP libraries
FROM composer:2.0 AS composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Proxy
FROM openruntimes/base:0.1.0 AS final

ARG OPR_PROXY_VERSION
ENV OPR_PROXY_VERSION=$OPR_PROXY_VERSION

# Source code
COPY ./app /usr/local/app
COPY ./src /usr/local/src

# Extensions and libraries
COPY --from=redis /usr/local/lib/php/extensions/no-debug-non-zts-20230831/redis.so /usr/local/lib/php/extensions/no-debug-non-zts-20230831/
COPY --from=composer /usr/local/src/vendor /usr/local/vendor

RUN echo extension=redis.so >> /usr/local/etc/php/conf.d/redis.ini

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -s -H "Authorization: Bearer ${OPR_PROXY_SECRET}" --fail http://127.0.0.1:80/v1/proxy/health

CMD [ "php", "/usr/local/app/http.php" ]