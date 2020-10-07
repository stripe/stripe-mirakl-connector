FROM trafex/alpine-nginx-php7:latest

ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.1.9/supercronic-linux-amd64 \
    SUPERCRONIC=supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=5ddf8ea26b56d4a7ff6faecdd8966610d5cb9d85

USER root

# Install packages
RUN apk add postgresql php7-pdo_pgsql php7-iconv php7-tokenizer php7-pcntl php7-gmp --no-cache

# Install composer
COPY --from=composer /usr/bin/composer /usr/bin/composer

# Copy configuration files
COPY examples/docker/app/config/php.ini /etc/php7/conf.d/settings.ini
COPY examples/docker/app/config/nginx.conf /etc/nginx/nginx.conf
COPY examples/docker/app/certs/* /etc/letsencrypt/
COPY examples/docker/app/config/crontab /etc/crontabs/connector-crontab
COPY examples/docker/app/config/supervisord.conf /etc/supervisor/conf.d/connector-supervisord.conf
RUN cat /etc/supervisor/conf.d/connector-supervisord.conf >> /etc/supervisor/conf.d/supervisord.conf \
    && rm /etc/supervisor/conf.d/connector-supervisord.conf

# Install dependencies
WORKDIR /var/www
COPY composer.* ./
RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader -q \
    && rm -rf /root/.composer

# Install the application
COPY --chown=nobody . ./
RUN composer dump-autoload --no-scripts --no-dev --optimize -q \
    && rm -rf /root/.composer \
    && bin/console --env=prod cache:warmup -q \
    && chown -R nobody ./var

# Install supercronic (for crons)
RUN curl -fsSLO "$SUPERCRONIC_URL" \
 && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
 && chmod +x "$SUPERCRONIC" \
 && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
 && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

# Switch to use a non-root user from here on
USER nobody
