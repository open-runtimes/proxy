# Install PHP libraries
FROM composer:2.0 as composer

WORKDIR /usr/local/src/

COPY composer.lock /usr/local/src/
COPY composer.json /usr/local/src/

RUN composer install --ignore-platform-reqs --optimize-autoloader \
    --no-plugins --no-scripts --prefer-dist

# Proxy
FROM appwrite/base:0.9.3 AS final

ARG OPR_PROXY_VERSION
ENV OPR_PROXY_VERSION=$OPR_PROXY_VERSION

# Source code
COPY ./app /usr/local/app
COPY ./src /usr/local/src

# Extensions and libraries
COPY --from=composer /usr/local/src/vendor /usr/local/vendor

HEALTHCHECK --interval=30s --timeout=15s --start-period=60s --retries=3 CMD curl -s -H "Authorization: Bearer ${OPR_PROXY_SECRET}" --fail http://127.0.0.1:80/v1/proxy/health

CMD [ "php", "/usr/local/app/http.php" ]