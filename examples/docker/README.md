Stripe Mirakl Connector Docker Sample
=======================

## About this sample

Based on [TrafeX/docker-php-nginx](https://github.com/TrafeX/docker-php-nginx), this sample project shows how to build and start the [Stripe Mirakl Connector](https://github.com/stripe/stripe-mirakl-connector) application on PHP-FPM 7.3, Nginx 1.16 and PostgreSQL 11.5 using Docker.

Although not production-ready as-is, it shows the basic configuration required.

Some examples of tasks required to complete the configuration for production:
- Replace the [certs](app/certs) content with valid certificates.
- Update [nginx.conf](app/config/nginx.conf) and [php.ini](app/config/php.ini) to fit your server configuration.
- Deny access to the OpenAPI specs.

## Installation

1. Rename [.env.dist](../../.env.dist) to `.env` and update the configuration, see the [Configuration](https://stripe.com/docs/plugins/mirakl/configuration) step in our docs.
2. From the [examples/docker](./) folder, run `make install` to start the application.
3. If you are starting the application for the first time, run `make db-install` to set up the database.

## Upgrade

1. From the root of your clone, run `git pull` to download changes.
2. Delete the `var` folder to clean the cache.
3. From the [examples/docker](./) folder, run `docker-compose up -d --build app` to rebuild and deploy the new version.
4. Run `make db-install` to check and apply database updates.

## Start jobs manually

1. Find the command you wish to run manually in [app/config/crontab](app/config/crontab).
2. Run the command through docker, e.g. `docker-compose run --rm app php bin/console connector:dispatch:process-transfer`

## Read logs

Logs are available under the `app` service: `docker-compose logs -tf app`.
