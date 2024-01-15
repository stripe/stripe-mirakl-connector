FROM php:8.2-fpm

ENV SUPERCRONIC_URL=https://github.com/aptible/supercronic/releases/download/v0.1.12/supercronic-linux-amd64 \
    SUPERCRONIC=supercronic-linux-amd64 \
    SUPERCRONIC_SHA1SUM=048b95b48b708983effb2e5c935a1ef8483d9e3e

USER root

# Configure PHP
COPY examples/docker/php/php.ini /usr/local/etc/php/conf.d/docker-php-config.ini

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
    && echo 'alias sf="php bin/console"' >> ~/.bashrc

RUN docker-php-ext-configure gd --with-jpeg --with-freetype

RUN docker-php-ext-install \
    pdo pgsql pdo_pgsql zip xsl gd intl opcache exif mbstring xml

# Install filebeat
RUN \
    cd /tmp \
    && wget https://artifacts.elastic.co/downloads/beats/filebeat/filebeat-7.12.0-linux-x86_64.tar.gz \
    && tar xzvf filebeat-7.12.0-linux-x86_64.tar.gz \
    && mkdir -p /usr/share/filebeat \
    && mv filebeat-7.12.0-linux-x86_64.tar.gz /usr/share/filebeat \
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
COPY ./../../. /var/www/mirakl-stripe/

WORKDIR /var/www/mirakl-stripe

RUN composer install

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
RUN mkdir /var/log/supervisor
RUN chown -R www-data /var/log/supervisor

USER www-data