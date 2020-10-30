# Changelog

- Version 1.2.2
    - Added validation and capture consumers to Docker example
    - Added validation command to Docker example
    - Updated cron schedule for orders in Docker example

- Version 1.2.1
    - Updated docker example to force Composer 1.10 (#27)

- Version 1.2.0
    - Added filter by orderId for transfers API (thanks @daniL16)
    - Added payment validation (PA01) workflow
    - Fixed commission handling in refunds workflow
    - Improved retry logic for refunds workflow
    - Improved retry logic for payouts workflow
    - Fixed transfers workflow for new users (thanks @eBusEntwHOFM)

- Version 1.1.6
    - Bump symfony/http-kernel from 4.4.7 to 4.4.13

- Version 1.1.5
    - Improved process-payouts job
    - Fixed rare cases of one cent discrepancy in amount calculation

- Version 1.1.4
    - Fixed retry attempts for already created transfers
    - Fixed missing listener for KYC update job

- Version 1.1.3
    - Fixed mapping for notifications of FailedRefundMessage

- Version 1.1.2
    - Fix test-db messages table handling
    - Retry failed and invalid_amount transfers in process transfers command

- Version 1.1.1
    - Moved Docker sample to a new [folder](examples/docker) for clarity
    - Docker sample is now based on TrafeX/docker-php-nginx

- Version 1.1.0
    - Upgrade to Symfony 4.4.7 (LTS)
    - Fix PHP requirement to 7.1
    - Add refund capabilities (thanks @ablanchard)
    - Fix Transfer amount when order includes taxes

- Version 1.0.0
    - Initiation of Stripe Express onboarding
    - Monitoring of Stripe accounts update
    - Transfers from platform Stripe account to sellers Stripe account based on Mirakl Orders
    - Payouts from sellers Stripe account to sellers bank account based on Mirakl Invoices
    - Transfers from sellers Stripe account to platform Stripe account based on Mirakl Invoices (subscriptions fees, etc.)
    - Server to server notifications:
        - Seller account is updated on Stripe
        - Transfer failed
        - Payout failed
    - Email notifications:
        - Server to server notifications fail
        - Daily recap of failed transfers and failed payouts
