Stripe Mirakl Connector Docker Sample
=======================

## About this sample

Based on [TrafeX/docker-php-nginx](https://github.com/TrafeX/docker-php-nginx), this sample project shows how to build and start the [Stripe Mirakl Connector](https://github.com/stripe/stripe-mirakl-connector) application on PHP-FPM 7.3, Nginx 1.16 and PostgreSQL 11.5 using Docker.

Although not production-ready as-is, it shows the basic configuration required.

Some examples of tasks required to complete the configuration for production:
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
The following operations needs to be done before starting the docker containers:

1. Create the `server.key`, `server.crt` and `ca.crt` files on your server
2. Gzip and encode the `server.key`, `server.crt` and `ca.crt` files in base64 using the following example command:
```bash
# Compress the SSL certificate file using gzip
gzip -c server.crt > server.crt.gz
# Encode the compressed file in Base64
base64 server.crt.gz > server.crt.gz.b64
```
3. Rename the `examples/docker/.env.dist` file to `examples/docker/.env`
4. Copy the content of `server.key.gz.b64` `server.crt.gz.b64` and `ca.crt.gz.b64` encoded files in the `examples/docker/.env`: 
```bash
PGSSLROOTCERT='content of the ca.crt file'
PGSSLCERT='content of the server.crt file'
PGSSLKEY='content of the server.key file'
```
5. Optional: if you want to view the logs of the database connection, uncomment the following lines in the `examples/docker/postgres/db_ssl.sh` file:
```bash
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET log_connections TO 'on';"
PGPASSWORD=$PASSWORD psql -U $USER -d $DB_NAME -c "ALTER SYSTEM SET log_hostname TO 'on';"
```

The following operations needs to be done after starting the docker containers:
1. Connect to the database container and run the following command:
```bash
sh /var/db_ssl.sh
```
2. Check if the connection is in secure mode