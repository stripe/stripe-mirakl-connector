Stripe Mirakl Connector Docker Sample
=======================

## About this sample

Based on [TrafeX/docker-php-nginx](https://github.com/TrafeX/docker-php-nginx), this sample project shows how to build and start the [Stripe Mirakl Connector](https://github.com/stripe/stripe-mirakl-connector) application on PHP-FPM 7.3, Nginx 1.16 and PostgreSQL 11.5 using Docker.

Although not production-ready as-is, it shows the basic configuration required.

Some examples of tasks required to complete the configuration for production:
- Replace the [certs](app/certs) content with valid certificates.
- Update [nginx.conf](app/config/nginx.conf) and [php.ini](app/config/php.ini) to fit your server configuration.
- Deny access to the OpenAPI specs.

## How-to use it

1. Rename [.env.dist](../../.env.dist) to `.env` and update the configuration, see [Configuration](https://stripe.com/docs/plugins/mirakl/configuration) step in our docs.
2. Start the application: `docker-compose up -d` or `make install`.
3. If you are starting the application for the first time, run `docker-compose run --rm app bin/console doctrine:migration:migrate --no-interaction` or `make db-install` to set up the database.

Logs are available under the `app` service: `docker-compose logs -tf app`.
