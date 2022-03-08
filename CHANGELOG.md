# Changelog

## Version 3.0.0
- Seller onboarding flow has been migrated from Oauth to Connect Onboarding. Make sure to configure your [Connect branding settings](https://dashboard.stripe.com/settings/connect) before upgrading.
- Consequently, the `/api/onboarding` endpoint has been removed, the `AccountOnboarding` entity has been deleted, and the `STRIPE_CLIENT_ID` environment variable is no longer needed.
- The `connector:sync:onboarding` command no longer uses the delay parameter. This parameter is now deprecated, please remove it from your crontab settings.
- You can now use the `SCHEME` environment variable to force HTTP when working locally or in a development environment.
- Stripe accounts are now created during the onboarding job and mapped with the corresponding Mirakl shop.
- Auto debit of negative balances is now disabled by default for new Stripe accounts (see #78).
- Improved Controllers tests.

## Version 2.1.5

- Fixed the Reason column in alerting emails.

## Version 2.1.4
- Fetch orders by id in batches of 100 (#91 thanks @cysp).
- Minor syntax improvements and fixes.

## Version 2.1.3

- Added miraklShopId to Stripe Account metadata (#85 thanks @christophersjchow).
- Improved logs readability + added option to output externally via Filebeat.
- Bumped dependencies.

## Version 2.1.2

- Added: fetch payment ID from PaymentMapping if not provided in order transaction_number.
- Fixed: mark PaymentMapping as captured if charge is captured.

## Version 2.1.1

- Fixed transfers not being created for subscription fees and extra invoices.
- Added migration to restore previously aborted subscription fees and extra invoices to the backlog.

## Version 2.1.0

- Bumped dependencies to remove deprecated and abandoned packages.
- Bumped PHPUnit to 9.5.
- Bumped required PHP version to 7.3 or higher.

## Version 2.0.4

- Fixed query params formatting for arrays (#72 thanks @fhervieux).

## Version 2.0.3

- Fixed serialization of array query params on service endpoints (#70 thanks @fhervieux).

## Version 2.0.2

- Fixed: escape `config.key` column name which is a reserved keyword in some DBMSs (#66).
- Improve performance in payment split workflow (#67 and #68).

## Version 2.0.1

- Fixed: use commercial ID in refund workflow instead of order ID to fetch the payment ID.

## Version 2.0.0

- Added: config service to better manage workflows checkpoints.
- Added: "on hold" and "aborted" statuses for better backlog management.
- Added: support for service orders by the payment split and validation workflows.
- Fixed: `account.updated` event will now result in 200 OK if the account is not on Mirakl.
- Fixed: captured amount for refused/cancelled orders.
- Breaking changes to the environment variables:
  - Default payment metadata key for the payment validation is now `mirakl_commercial_order_id`. The connector will keep using your custom metadata key if you set one. This only affects users relying on the payment validation workflow and with no set `MIRAKL_METADATA_ORDER_ID` variable.
- Breaking changes to resources exposed by the connector API:
  - Resource: renamed `StripeCharge` entity to `PaymentMapping`.
  - Resource: removed `TRANSFER_INVALID_AMOUNT` status from `StripeTransfer`.
  - Resource: added `*_ABORTED` and `*_ON_HOLD` statuses to `StripeTransfer`, `StripePayout` and `StripeRefund`.
  - Resource: added `type` to `StripeRefund`.
  - Notification: `stripePayoutId` in the `payout.failed` notification has been deprecated in favor of `payoutId`.
  - Notification: added `type` to `payout.failed`.

## Version 1.2.7

- Fixed capture flow when using PaymentIntents (#46).

## Version 1.2.6

- Renamed sequence to match code naming (#45).
- Fixed incorrect method call in webhook controller (#43 thanks @christophersjchow).
- Improve compatibility with hybrid orders in capture flow (#42).

## Version 1.2.5

- Fixed payment validation + split combination (#38).
- Prevent failed payouts from being dispatched twice (#40).

## Version 1.2.4

- Fetch shops from SERVICE domain too in onboarding job (#31 thanks @fhervieux).

## Version 1.2.3

- Added distinct webhooks for operator and sellers (#29).
- Added payment cancellation for orders refused by all sellers (#30).

## Version 1.2.2

- Added validation and capture consumers to Docker example.
- Added validation command to Docker example.
- Updated cron schedule for orders in Docker example.

## Version 1.2.1

- Updated docker example to force Composer 1.10 (#27).

## Version 1.2.0

- Added filter by orderId for transfers API (thanks @daniL16).
- Added payment validation (PA01) workflow.
- Fixed commission handling in refunds workflow.
- Improved retry logic for refunds workflow.
- Improved retry logic for payouts workflow.
- Fixed transfers workflow for new users (thanks @eBusEntwHOFM).

## Version 1.1.6

- Bump symfony/http-kernel from 4.4.7 to 4.4.13.

## Version 1.1.5

- Improved process-payouts job.
- Fixed rare cases of one cent discrepancy in amount calculation.

## Version 1.1.4

- Fixed retry attempts for already created transfers.
- Fixed missing listener for KYC update job.

## Version 1.1.3

- Fixed mapping for notifications of FailedRefundMessage.

## Version 1.1.2

- Fix test-db messages table handling.
- Retry failed and invalid_amount transfers in process transfers command.

## Version 1.1.1

- Moved Docker sample to a new [folder](examples/docker) for clarity.
- Docker sample is now based on TrafeX/docker-php-nginx.

## Version 1.1.0

- Upgrade to Symfony 4.4.7 (LTS).
- Fix PHP requirement to 7.1.
- Add refund capabilities (thanks @ablanchard).
- Fix Transfer amount when order includes taxes.

## Version 1.0.0

- Initiation of Stripe Express onboarding.
- Monitoring of Stripe accounts update.
- Transfers from platform Stripe account to sellers Stripe account based on Mirakl Orders.
- Payouts from sellers Stripe account to sellers bank account based on Mirakl Invoices.
- Transfers from sellers Stripe account to platform Stripe account based on Mirakl Invoices (subscriptions fees, etc.).
- Server to server notifications:
  - Seller account is updated on Stripe.
  - Transfer failed.
  - Payout failed.
- Email notifications:
  - Server to server notifications fail.
  - Daily recap of failed transfers and failed payouts.
