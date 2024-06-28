FROM php:8.2.0-fpm as application


ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.2.29/supercronic-linux-amd64 \
    SUPERCRONIC=supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=cd48d45c4b10f3f0bfdd3a57d054cd05ac96812b

USER root

# Configure PHP
COPY examples/docker/php/php.ini /usr/local/etc/php/conf.d/docker-php-config.ini
COPY examples/docker/php/php_run.sh /usr/share/php_run.sh
RUN chmod +x /usr/share/php_run.sh

COPY examples/docker/certs/ca.crt /etc/ssl/certs/root.crt
COPY examples/docker/certs/server.crt /etc/ssl/certs/server.crt
COPY examples/docker/certs/server.key /etc/ssl/private/server.key

RUN chmod 600 /etc/ssl/private/server.key
RUN chmod 600 /etc/ssl/certs/server.crt
RUN chmod 600 /etc/ssl/certs/root.crt
RUN chown -R www-data:www-data /etc/ssl/private
RUN chown www-data:www-data /etc/ssl/certs/server.crt
RUN chown www-data:www-data /etc/ssl/certs/root.crt


# Set environment variables
ENV PGSSLROOTCERT=/etc/ssl/certs/root.crt
ENV PGSSLCERT=/etc/ssl/certs/server.crt
ENV PGSSLKEY=/etc/ssl/private/server.key
ENV PGSSLMODE=verify-full

# Install packages
RUN apt-get update && apt-get install -y \
    gnupg \
    g++ \
    procps \
    openssl \
    git \
    unzip \
    zip \
    zlib1g-dev \
    libzip-dev \
    libfreetype6-dev \
    libpng-dev \
    libjpeg-dev \
    libicu-dev  \
    libonig-dev \
    libxslt1-dev \
    acl \
    libpq-dev \
    wget \
    libgmp-dev \
    cron \
    supervisor \
    && echo 'alias sf="php bin/console"' >> ~/.bashrc

RUN docker-php-ext-configure gd --with-jpeg --with-freetype

RUN docker-php-ext-install \
    pdo pgsql pdo_pgsql zip xsl gd intl opcache exif mbstring xml gmp

# Install filebeat
RUN \
    cd /tmp \
    && wget https://artifacts.elastic.co/downloads/beats/filebeat/filebeat-8.12.1-linux-x86_64.tar.gz \
    && tar xzvf filebeat-8.12.1-linux-x86_64.tar.gz \
    && mkdir -p /usr/share/filebeat \
    && mv filebeat-8.12.1-linux-x86_64/* /usr/share/filebeat \
    && mkdir -p /usr/share/filebeat/logs /usr/share/filebeat/data \
    && rm -R /tmp/*

ENV PATH $PATH:/usr/share/filebeat
RUN chown -R www-data /usr/share/filebeat

# Copy configuration files
COPY examples/docker/config/filebeat.yml /usr/share/filebeat/filebeat.yml
RUN chmod go-w /usr/share/filebeat/filebeat.yml
COPY examples/docker/certs/* /etc/letsencrypt/
COPY examples/docker/config/crontab /etc/crontabs/connector-crontab
COPY examples/docker/config/supervisord.conf /etc/supervisor/conf.d/connector-supervisord.conf
RUN cat /etc/supervisor/conf.d/connector-supervisord.conf >> /etc/supervisor/conf.d/supervisord.conf \
    && rm /etc/supervisor/conf.d/connector-supervisord.conf

# Install composer
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN mkdir -p /var/www/mirakl-stripe
COPY ./../../ /var/www/mirakl-stripe/

WORKDIR /var/www/mirakl-stripe

RUN composer install -n --no-plugins --no-scripts

# Install the application
RUN chown -R www-data ./
RUN php bin/console --env=prod cache:warmup -q \
    && chown -R www-data ./var

# Install supercronic (for crons)
RUN curl -fsSLO "$SUPERCRONIC_URL" \
    && echo "${SUPERCRONIC_SHA1SUM}  ${SUPERCRONIC}" | sha1sum -c - \
    && chmod +x "$SUPERCRONIC" \
    && mv "$SUPERCRONIC" "/usr/local/bin/${SUPERCRONIC}" \
    && ln -s "/usr/local/bin/${SUPERCRONIC}" /usr/local/bin/supercronic

# Create supervisor log directory and let www-data user read it
RUN chown -R www-data /var/log/supervisor

USER www-data
CMD ["/usr/share/php_run.sh"]

FROM nginx:latest as nginx

RUN mkdir -p /var/www/mirakl-stripe
COPY --from=application /var/www/mirakl-stripe/ /var/www/mirakl-stripe/
COPY examples/docker/nginx/nginx.conf  /etc/nginx/
COPY examples/docker/nginx/default.conf /etc/nginx/conf.d/
RUN echo "upstream php-upstream { server php:9000; }" > /etc/nginx/conf.d/upstream.conf

COPY examples/docker/nginx/nginxlogrotate /etc/logrotate.d/nginx

EXPOSE 8000
EXPOSE 443
