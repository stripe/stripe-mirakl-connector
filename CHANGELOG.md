# Changelog

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