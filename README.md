Stripe Mirakl Connector
=======================

[![Build Status](https://github.com/stripe/stripe-mirakl-connector/workflows/.github/workflows/build.yml/badge.svg)](https://github.com/stripe/stripe-mirakl-connector/actions)
[![Coverage Status](https://coveralls.io/repos/github/stripe/stripe-mirakl-connector/badge.svg?branch=master)](https://coveralls.io/github/stripe/stripe-mirakl-connector?branch=master)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

Stripe provides a connector to allow marketplaces powered by Mirakl to onboard sellers on Stripe and pay them out automatically.

Learn how to use the connector in the [Stripe Docs](https://stripe.com/docs/plugins/mirakl).

## Containerized examples

Deploying the application manually can be non-trivial and sometimes unstable. We recommend using containerization instead.

For Docker users, you can find an example under [examples/docker](examples/docker).

Feel free to share a working example using your favorite tool via a pull request.

## Versioning

We use the MAJOR.MINOR.PATCH semantic:

- MAJOR versions contain incompatible API changes, check the [CHANGELOG] before upgrading
- MINOR versions contain new functionality added in a backwards compatible manner
- PATCH versions contain bug fixes added in a backwards compatible manner

Upgrading is safe for all version types.

Downgrading is safe for MINOR and PATCH versions. You shouldn't downgrade between MAJOR versions if the connector was already used in production.

To upgrade:

1. Delete the `var` folder to clean the cache.
2. From the root of your clone, run `git pull` to download changes.
3. [Reinstall](https://stripe.com/docs/plugins/mirakl/install#manually) the connector.

To downgrade:

1. Delete the `var` folder to clean the cache.
2. From the root of your clone, run `git reset` to the desired commit or tag.
3. [Reinstall](https://stripe.com/docs/plugins/mirakl/install#manually) the connector.
4. Find the latest database migration version for your clone in [src/Migrations](src/Migrations).
5. Replace the usual command to execute the database migrations with that version, e.g. `bin/console doctrine:migration:migrate --no-interaction 20201016122853`

If you use the Docker sample, see instead the specific [instructions](examples/docker#upgrade) on how to upgrade and downgrade.

## Contributing to the application

Pull requests are welcome. For major changes, open an issue first to discuss what you would like to change.

Please make sure to update tests accordingly.

## License

[MIT](LICENSE.md)
