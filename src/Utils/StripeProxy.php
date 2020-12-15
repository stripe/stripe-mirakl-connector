<?php

namespace App\Utils;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
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
 * Separated (and public) methods for test purposes, as it is not possible to mock static calls.
 *
 * @codeCoverageIgnore
 */
class StripeProxy implements LoggerAwareInterface
{
    use LoggerAwareTrait;

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

    // OAUTH
    public function loginWithCode(string $code): StripeObject
    {
        return \Stripe\OAuth::token([
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);
    }

    // Account
    public function accountRetrieve(string $stripeUserId): Account
    {
        $this->logger->info('[Stripe API] Call to \Stripe\Account::retrieve', [
            'stripeUserId' => $stripeUserId,
        ]);

        return \Stripe\Account::retrieve($stripeUserId);
    }

    public function accountCreateLoginLink(string $stripeUserId): LoginLink
    {
        return \Stripe\Account::createLoginLink($stripeUserId);
    }

    public function updateAccount(string $stripeUserId, array $params = []): Account
    {
        $this->logger->info('[Stripe API] Call to \Stripe\Account::update', [
            'stripeUserId' => $stripeUserId,
        ]);

        return \Stripe\Account::update($stripeUserId, $params);
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
    public function createTransfer(string $currency, int $amount, ?string $stripeConnectedAccountId, ?string $transactionId, array $metadata = [], bool $fromConnectedAccount = false)
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $params = [
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => $mergedMetadata,
        ];
        $options = [];
        if (null !== $transactionId) {
            $params['source_transaction'] = $transactionId;
        }
        if ($fromConnectedAccount) {
            $platformAccount = \Stripe\Account::retrieve();
            $params['destination'] = $platformAccount->id;
            $options['stripe_account'] = $stripeConnectedAccountId;
        } else {
            $params['destination'] = $stripeConnectedAccountId;
        }

        $this->logger->info('[Stripe API] Call to \Stripe\Transfer::create', array_merge($params, $options));

        return \Stripe\Transfer::create($params, $options);
    }

    // Payout
    public function createPayout(string $currency, int $amount, string $stripeAccountId, array $metadata = [])
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $this->logger->info('[Stripe API] Call to \Stripe\Payout::create', [
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => $mergedMetadata,
            'stripe_account' => $stripeAccountId,
        ]);

        return \Stripe\Payout::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => $mergedMetadata,
        ], [
            'stripe_account' => $stripeAccountId,
        ]);
    }

    // Refund
    public function createRefund(int $amount, ?string $transactionId, array $metadata = [])
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $params = [
            'charge' => $transactionId,
            'amount' => $amount,
            'metadata' => $mergedMetadata,
            'reverse_transfer' => false,
        ];

        $this->logger->info('[Stripe API] Call to \Stripe\Refund::create', $params);

        return \Stripe\Refund::create($params);
    }

    public function listRefunds(?string $transactionId)
    {
        return \Stripe\Charge::retrieve(['id' => $transactionId])->refunds;
    }

    // Reversal
    public function reverseTransfer(int $amount, string $transfer_id, array $metadata = [])
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $params = [
            'amount' => $amount,
            'metadata' => $mergedMetadata,
        ];

        return \Stripe\Transfer::createReversal($transfer_id, $params);
    }

    public function listReversals(?string $transfer_id)
    {
        return \Stripe\Transfer::retrieve(['id' => $transfer_id])->reversals;
    }

    /**
     * @param string $paymentId
     * @param int $amount
     * @return Charge|PaymentIntent
     * @throws \Stripe\Exception\ApiErrorException
     */
    public function capture(string $paymentId, int $amount)
    {
        $params = [];

        $prefix = substr($paymentId, 0, 2);

        if ('pi' === $prefix) {
            $obj = PaymentIntent::constructFrom(['id' => $paymentId]);
            $params['amount_to_capture'] = $amount;
            $message = '[Stripe API] Call to PaymentIntent/Capture';
        } elseif ('ch' === $prefix || 'py' === $prefix) {
            // Check for PI
            $charge = $this->chargeRetrieve($paymentId);
            if (isset($charge->payment_intent) && !empty($charge->payment_intent)) {
                return $this->capture($charge->payment_intent, $amount);
            }

            $obj = Charge::constructFrom(['id' => $paymentId]);
            $params['amount'] = $amount;
            $message = '[Stripe API] Call to Charge/Capture';
        } else {
            throw new \Exception('payment not yet managed');
        }

        $this->logger->info($message, [
            'stripeId' => $paymentId,
            'amount' => $amount,
        ]);

        return $obj->capture($params);
    }

    /**
     * @param string $paymentId
     * @param int $amount
     * @return Charge|PaymentIntent
     * @throws ApiErrorException
     */
    public function cancelBeforeCapture(string $paymentId, int $amount)
    {
        $prefix = substr($paymentId, 0, 2);

        if ('pi' === $prefix) {
            $obj = PaymentIntent::constructFrom(['id' => $paymentId]);
            $obj->cancel();
            $message = '[Stripe API] Call to PaymentIntent/Cancel';
            $this->logger->info($message, ['stripeId' => $paymentId]);
        } elseif ('ch' === $prefix || 'py' === $prefix) {
            // Check for PI
            $charge = $this->chargeRetrieve($paymentId);
            if (isset($charge->payment_intent) && !empty($charge->payment_intent)) {
                return $this->cancelBeforeCapture($charge->payment_intent, $amount);
            }

            $obj = $this->createRefund($amount, $paymentId);
        } else {
            throw new \Exception('payment not yet managed');
        }

        return $obj;
    }

    // Payment intent
    public function paymentIntentRetrieve(string $stripePaymentIntentId): PaymentIntent
    {
        $this->logger->info('[Stripe API] Call to \Stripe\PaymentIntent::retrieve', [
            'stripePaymentIntentId' => $stripePaymentIntentId,
        ]);

        return PaymentIntent::retrieve($stripePaymentIntentId);
    }

    // Charge
    public function chargeRetrieve(string $stripeChargeId): Charge
    {
        $this->logger->info('[Stripe API] Call to \Stripe\Charge::retrieve', [
            'stripeChargeId' => $stripeChargeId,
        ]);

        return Charge::retrieve($stripeChargeId);
    }
}
