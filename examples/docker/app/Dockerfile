FROM trafex/alpine-nginx-php7:1.10.0

ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.1.12/supercronic-linux-amd64 \
    SUPERCRONIC=supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=048b95b48b708983effb2e5c935a1ef8483d9e3e

USER root

# Install packages
RUN apk add postgresql php7-pdo_pgsql php7-iconv php7-tokenizer php7-pcntl php7-gmp libc6-compat curl jq --no-cache

# Install filebeat
RUN \
    cd /tmp \
    && wget https://artifacts.elastic.co/downloads/beats/filebeat/filebeat-7.12.0-linux-x86_64.tar.gz \
    && tar xzvf filebeat-7.12.0-linux-x86_64.tar.gz \
    && mv filebeat-7.12.0-linux-x86_64 /usr/share/filebeat \
    && mkdir /usr/share/filebeat/logs /usr/share/filebeat/data \
    && rm /tmp/*
ENV PATH $PATH:/usr/share/filebeat
RUN chown -R nobody /usr/share/filebeat

# Install composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy configuration files
COPY examples/docker/app/config/filebeat.yml /usr/share/filebeat/filebeat.yml
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

# Create supervisor log directory and let nobody user read it
RUN mkdir /var/log/supervisor
RUN chown -R nobody /var/log/supervisor

# Add nginx logrotate
COPY examples/docker/app/config/nginxlogrotate /etc/logrotate.d/nginx

# Switch to use a non-root user from here on
USER nobody
