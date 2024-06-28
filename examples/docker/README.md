Stripe Mirakl Connector Docker Sample
=======================

## About this sample

Based on [TrafeX/docker-php-nginx](https://github.com/TrafeX/docker-php-nginx), this sample project shows how to build and start the [Stripe Mirakl Connector](https://github.com/stripe/stripe-mirakl-connector) application on PHP-FPM 7.3, Nginx 1.16 and PostgreSQL 11.5 using Docker.

Although not production-ready as-is, it shows the basic configuration required.

Some examples of tasks required to complete the configuration for production:
- Replace the [certs](examples/docker/certs) content with valid certificates.
- Update [nginx.conf](app/config/nginx.conf) and [php.ini](app/config/php.ini) to fit your server configuration.
- Deny access to the OpenAPI specs.

## Installation

1. Rename [.env.dist](../../.env.dist) to `.env` and update the configuration, see the [Configuration](https://stripe.com/docs/plugins/mirakl/configuration) step in our docs.
2. From the [examples/docker](./) folder, run `docker compose build --no-cache` to build the application.
3. After the build is done successfully, from the [examples/docker](./) folder, run `docker compose up` to start the application.

## Versioning

See also [Versioning](../../README.md#versioning).

To upgrade:

1. Delete the `var` folder to clean the cache.
2. From the root of your clone, run `git pull` to download changes.
3. From the [examples/docker](./) folder, run `docker-compose up -d --build app` to rebuild and deploy the new version.
4. Run `make db-install` to check and apply database updates.

To downgrade:

1. Find the latest database migration for the targeted version in [src/Migrations](../../src/Migrations).
2. Run the database migrations with that version, e.g. `docker-compose run --rm app bin/console doctrine:migration:migrate --no-interaction 20201016122853`
3. Delete the `var` folder to clean the cache.
4. From the root of your clone, run `git reset` to the desired commit or tag.
5. From the [examples/docker](./) folder, run `docker-compose up -d --build app` to rebuild and deploy the desired version.

## Start jobs manually

1. Find the command you wish to run manually in [app/config/crontab](app/config/crontab).
2. Run the command through docker, e.g. `docker-compose run --rm app php bin/console connector:dispatch:process-transfer`

## Read logs

Logs are available under the `app` service: `docker-compose logs -tf app`.

## Setting up SSL Connection

Create the `server.key` `server.crt` and `ca.crt` files in the `examples/docker/certs` folder.
Update in the `.env` file the `DATABASE_URL` with the following format: `pgsql://symfony:symfony@db:5432/symfony?sslmode=require`
```
