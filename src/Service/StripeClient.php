<?php

namespace App\Service;

use Shivas\VersioningBundle\Service\VersionManager;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Stripe\ApiRequestor;
use Stripe\Account;
use Stripe\AccountLink;
use Stripe\Charge;
use Stripe\Event;
use Stripe\LoginLink;
use Stripe\PaymentIntent;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\Transfer;
use Stripe\TransferReversal;
use Stripe\Webhook;

/**
 * @codeCoverageIgnore
 */
class StripeClient
{

    /**
     * @var string
     */
    private $webhookSellerSecret;

    /**
     * @var string
     */
    private $webhookOperatorSecret;

    /**
     * @var string
     */
    private $redirectOnboarding;

    public const APP_NAME = 'MiraklConnector';
    public const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    public const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    public const APP_API_VERSION = '2019-08-14';

    /**
     * @var string
     */
    private $version;

    public function __construct(
        string $stripeClientSecret,
        VersionManager $versionManager,
        string $webhookSellerSecret,
        string $webhookOperatorSecret,
        string $redirectOnboarding
    ) {
        $this->version = $versionManager->getVersion();

        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(self::APP_NAME, $this->version, self::APP_REPO, self::APP_PARTNER_ID);
        Stripe::setApiVersion(self::APP_API_VERSION);

        $this->webhookSellerSecret = $webhookSellerSecret;
        $this->webhookOperatorSecret = $webhookOperatorSecret;
        $this->redirectOnboarding = $redirectOnboarding;
    }

    private function getDefaultMetadata(): array
    {
        return [
            'pluginName' => self::APP_NAME,
            'pluginVersion' => $this->version,
        ];
    }

    // Account
    public function retrieveAccount(string $accountId): Account
    {
        return Account::retrieve($accountId);
    }

    public function createAccountLink(string $accountId, $type = 'account_onboarding'): AccountLink
    {
        return AccountLink::create([
            'account' => $accountId,
            'refresh_url' => 'https://example.com/reauth',
            'return_url' => $this->redirectOnboarding,
            'type' => $type,
        ]);
    }

    public function createLoginLink(string $accountId): LoginLink
    {
        return Account::createLoginLink($accountId);
    }

    public function createStripeAccount(int $shopId, array $details): Account
    {
        return Account::create(array_merge([
            'type' => 'express',
            'settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]],
            'metadata' => [
                'miraklShopId' => $shopId
            ]
        ], $details));
    }

    // Signature
    public function webhookConstructEvent(string $payload, string $signatureHeader, bool $isOperatorWebhook = false): Event
    {
        $webhookSecret = $isOperatorWebhook ? $this->webhookOperatorSecret : $this->webhookSellerSecret;

        return Webhook::constructEvent(
            $payload,
            $signatureHeader,
            $webhookSecret
        );
    }

    public function setHttpClient(ClientInterface $client)
    {
        ApiRequestor::setHttpClient($client);
    }

    // Transfer
    public function createTransfer(string $currency, int $amount, string $accountId, ?string $chargeId, array $metadata = []): Transfer
    {
        return Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $accountId,
            'source_transaction' => $chargeId
        ]);
    }

    // Transfer
    public function createTransferFromConnectedAccount(string $currency, int $amount, string $accountId, array $metadata = []): Transfer
    {
        $platformAccount = Account::retrieve();
        return Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $platformAccount->id
        ], ['stripe_account' => $accountId]);
    }

    // Reversal
    public function reverseTransfer(int $amount, string $transferId, array $metadata = []): TransferReversal
    {
        return Transfer::createReversal($transferId, [
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ]);
    }

    // Payout
    public function createPayout(string $currency, int $amount, string $stripeAccountId, array $metadata = []): Payout
    {
        return Payout::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], [
            'stripe_account' => $stripeAccountId,
        ]);
    }

    // Refund
    public function createRefund(string $charge, ?int $amount, array $metadata = []): Refund
    {
        return Refund::create([
            'charge' => $charge,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ]);
    }

    /**
     * @param string $paymentId
     * @param int $amount
     * @return Charge|PaymentIntent
     * @throws ApiErrorException
     */
    public function capturePayment(string $paymentId, int $amount)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);
                return $pi->capture(['amount_to_capture' =>  $amount]);
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->capturePayment($paymentIntentId, $amount);
                }

                return $charge->capture(['amount' =>  $amount]);
            default:
                throw new \Exception('Unexpected payment type: ' . $paymentId);
        }
    }

    /**
     * @param string $paymentId
     * @return Refund|PaymentIntent
     * @throws ApiErrorException
     */
    public function cancelPayment(string $paymentId)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);
                $pi->cancel();
                return $pi;
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->cancelPayment($paymentIntentId);
                }

                return $this->createRefund($paymentId, null);
            default:
                throw new \Exception('Unexpected payment type: ' . $paymentId);
        }
    }

    // Payment intent
    public function paymentIntentRetrieve(string $stripePaymentIntentId): PaymentIntent
    {
        return PaymentIntent::retrieve($stripePaymentIntentId);
    }

    // Charge
    public function chargeRetrieve(string $stripeChargeId): Charge
    {
        return Charge::retrieve($stripeChargeId);
    }
}
