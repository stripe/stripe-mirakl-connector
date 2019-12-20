FROM php:7.3-fpm-alpine

# install system basics
RUN apk add supervisor postgresql-dev --no-cache \
    && docker-php-ext-install pdo_pgsql opcache \
    && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

ARG SKIP_XDEBUG=false
RUN if [ "$SKIP_XDEBUG" = "false" ] ; then \
        apk add --no-cache $PHPIZE_DEPS \
        && pecl install xdebug apcu \
        && docker-php-ext-enable xdebug apcu \
        && apk del $PHPIZE_DEPS \
        && rm -rf /tmp/pear; \
    else \
        apk add --no-cache $PHPIZE_DEPS \
        && pecl install apcu \
        && docker-php-ext-enable apcu \
        && apk del $PHPIZE_DEPS \
        && rm -rf /tmp/pear; \
    fi ;

ENV COMPOSER_ALLOW_SUPERUSER=1
# install composer
RUN apk add curl --no-cache \
    && curl -s https://getcomposer.org/composer.phar > /usr/local/bin/composer \
    && chmod a+x /usr/local/bin/composer \
    && composer global require hirak/prestissimo -q --no-plugins --no-scripts \
    && rm -rf /root/.composer \
    && apk del curl

COPY docker/php-fpm/supervisord.conf /etc/supervisord.conf
COPY docker/php-fpm/connector-crontab /etc/crontabs/connector-crontab
RUN crontab /etc/crontabs/connector-crontab

WORKDIR /var/www

# copy the source code
COPY composer.* ./
RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader -q \
    && rm -rf /root/.composer
COPY --chown=www-data:www-data . ./

RUN composer dump-autoload --no-scripts --no-dev --optimize -q \
    && rm -rf /root/.composer \
    && bin/console --env=prod cache:warmup -q \
    && chown -R www-data:www-data /var/www/var

CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
