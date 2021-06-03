<?php

namespace App\Service;

use Shivas\VersioningBundle\Service\VersionManager;
use Stripe\Exception\ApiErrorException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Stripe\StripeObject;
use Stripe\Account;
use Stripe\Charge;
use Stripe\Event;
use Stripe\LoginLink;
use Stripe\PaymentIntent;

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

    public const APP_NAME = 'MiraklConnector';
    public const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    public const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    public const APP_API_VERSION = '2019-08-14';

    /**
     * @var string
     */
    private $version;

    public function __construct(string $stripeClientSecret, VersionManager $versionManager, string $webhookSellerSecret, string $webhookOperatorSecret)
    {
        $this->version = $versionManager->getVersion();

        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(self::APP_NAME, $this->version, self::APP_REPO, self::APP_PARTNER_ID);
        Stripe::setApiVersion(self::APP_API_VERSION);

        $this->webhookSellerSecret = $webhookSellerSecret;
        $this->webhookOperatorSecret = $webhookOperatorSecret;
    }

    private function getDefaultMetadata(): array
    {
        return [
            'pluginName' => self::APP_NAME,
            'pluginVersion' => $this->version,
        ];
    }

    // Account
    public function accountRetrieve(string $stripeUserId): Account
    {
        return \Stripe\Account::retrieve($stripeUserId);
    }

    public function accountCreateLoginLink(string $stripeUserId): LoginLink
    {
        return \Stripe\Account::createLoginLink($stripeUserId);
    }

    // OAUTH
    public function loginWithCode(string $code): StripeObject
    {
        return \Stripe\OAuth::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
    }

    public function setMiraklShopId(string $stripeAccountId, int $miraklShopId): Account
    {
        $params = [
            'metadata' => [
                'miraklShopId' => $miraklShopId
            ]
        ];

        return \Stripe\Account::update($stripeAccountId, $params);
    }

    public function setPayoutToManual(string $stripeAccountId): Account
    {
        $params = [
            'settings' => [
                'payouts' => [
                    'schedule' => [
                        'interval' => 'manual'
                    ]
                ]
            ]
        ];

        return \Stripe\Account::update($stripeAccountId, $params);
    }

    // Signature
    public function webhookConstructEvent(string $payload, string $signatureHeader, bool $isOperatorWebhook = false): Event
    {
        $webhookSecret = $isOperatorWebhook ? $this->webhookOperatorSecret : $this->webhookSellerSecret;

        return \Stripe\Webhook::constructEvent(
            $payload,
            $signatureHeader,
            $webhookSecret
        );
    }

    public function setHttpClient(ClientInterface $client)
    {
        \Stripe\ApiRequestor::setHttpClient($client);
    }

    // Transfer
    public function createTransfer(string $currency, int $amount, string $accountId, ?string $chargeId, array $metadata = [])
    {
        return \Stripe\Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $accountId,
            'source_transaction' => $chargeId
        ]);
    }

    // Transfer
    public function createTransferFromConnectedAccount(string $currency, int $amount, string $accountId, array $metadata = [])
    {
        $platformAccount = \Stripe\Account::retrieve();
        return \Stripe\Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $platformAccount->id
        ], [ 'stripe_account' => $accountId ]);
    }

    // Reversal
    public function reverseTransfer(int $amount, string $transferId, array $metadata = [])
    {
        return \Stripe\Transfer::createReversal($transferId, [
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ]);
    }

    // Payout
    public function createPayout(string $currency, int $amount, string $stripeAccountId, array $metadata = [])
    {
        return \Stripe\Payout::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], [
            'stripe_account' => $stripeAccountId,
        ]);
    }

    // Refund
    public function createRefund(string $charge, ?int $amount, array $metadata = [])
    {
        return \Stripe\Refund::create([
            'charge' => $charge,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ]);
    }

    /**
     * @param string $paymentId
     * @param int $amount
     * @return Charge|PaymentIntent
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function capturePayment(string $paymentId, int $amount)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);
                return $pi->capture([ 'amount_to_capture' =>  $amount]);
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->capturePayment($paymentIntentId, $amount);
                }

                return $charge->capture([ 'amount' =>  $amount]);
            default:
                throw new \Exception('Unexpected payment type: ' . $paymentId);
        }
    }

    /**
     * @param string $paymentId
     * @return Charge|PaymentIntent
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
