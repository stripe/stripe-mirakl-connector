Stripe Mirakl Connector
=======================

[![Build Status](https://travis-ci.org/stripe/stripe-mirakl-connector.svg?branch=master)](https://travis-ci.org/stripe/stripe-mirakl-connector)
[![Coverage Status](https://coveralls.io/repos/github/stripe/stripe-mirakl-connector/badge.svg?branch=master)](https://coveralls.io/github/stripe/stripe-mirakl-connector?branch=master)
[![Maintainability](https://api.codeclimate.com/v1/badges/14482554769acb66fb4d/maintainability)](https://codeclimate.com/repos/5d823394302a1b018b00ff58/maintainability)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)


Stripe provides a connector to allow marketplaces powered by Mirakl to onboard sellers on Stripe and pay them out automatically.

Learn how to use the connector in the [Stripe Docs](https://stripe.com/docs/plugins/mirakl).

# Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Please make sure to update tests as appropriate.

# How to start a development environment using docker

`docker-compose` starts 3 containers:

* `db`: This is the PostreSQL database container (can be changed to postgresql or whatever you prefer in the `docker-compose.yml` file),
* `php`: This is the PHP container including the application volume mounted on. It uses supervisord to start PHP, the required workers and cron.
* `nginx`: This is the Nginx webserver container exposing the application.

This results in the following running containers:

```bash
> $ docker-compose ps
          Name                        Command               State                          Ports
------------------------------------------------------------------------------------------------------------------------
mirakl-stripe_db_1         docker-entrypoint.sh postgres    Up      5432/tcp
mirakl-stripe_nginx_1      nginx -g daemon off;             Up      0.0.0.0:443->443/tcp, 0.0.0.0:80->80/tcp
mirakl-stripe_php_1        docker-php-entrypoint /usr ...   Up      9000/tcp, 0.0.0.0:9001->9001/tcp
```

# Read logs

All application logs in the Docker containers are redirected to `stdout` and `stderr`, meaning you can access Symfony application logs by running `docker-compose logs php`.

# License

[MIT](LICENSE.md)
