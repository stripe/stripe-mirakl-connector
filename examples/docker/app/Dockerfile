FROM docker.io/trafex/php-nginx:3.4.0

ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.27/supercronic-linux-amd64 \
    SUPERCRONIC=supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=7dadd4ac827e7bd60b386414dfefc898ae5b6c63

USER root

# Install packages
RUN apk add postgresql php82-pdo_pgsql php82-iconv php82-tokenizer php82-pcntl php82-gmp php82-simplexml libc6-compat curl jq --no-cache

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
COPY --from=docker.io/composer:2 /usr/bin/composer /usr/bin/composer

# Copy configuration files
COPY examples/docker/app/config/filebeat.yml /usr/share/filebeat/filebeat.yml
RUN chmod go-w /usr/share/filebeat/filebeat.yml
COPY examples/docker/app/config/php.ini /etc/php82/conf.d/settings.ini
COPY examples/docker/app/config/nginx.conf /etc/nginx/nginx.conf
COPY examples/docker/app/certs/* /etc/letsencrypt/
COPY examples/docker/app/config/crontab /etc/crontabs/connector-crontab
COPY examples/docker/app/config/supervisord.conf /etc/supervisor/conf.d/connector-supervisord.conf
RUN cat /etc/supervisor/conf.d/connector-supervisord.conf >> /etc/supervisor/conf.d/supervisord.conf \
    && rm /etc/supervisor/conf.d/connector-supervisord.conf

# Install dependencies
WORKDIR /var/www
COPY composer.* ./
RUN composer install --prefer-dist --no-scripts --no-dev --no-autoloader \
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
