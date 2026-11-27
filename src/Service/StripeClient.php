<?php

namespace App\Service;

use Stripe\Account;
use Stripe\AccountLink;
use Stripe\ApiRequestor;
use Stripe\Charge;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\AuthenticationException;
use Stripe\Exception\CardException;
use Stripe\Exception\IdempotencyException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\PermissionException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\HttpClient\ClientInterface;
use Stripe\LoginLink;
use Stripe\PaymentIntent;
use Stripe\Payout;
use Stripe\Refund;
use Stripe\StripeClient as StripeSdkClient;
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
    private $version;

    private StripeSdkClient $client;

    private bool $useLegacyStripeApi;

    /**
     * @var bool
     */
    private $verifyWebhookSignature;

    public const APP_NAME = 'MiraklConnector';
    public const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    public const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    public const APP_API_VERSION = '2026-11-18.endive';
    public const LEGACY_API_VERSION = '2019-08-14';

    public function __construct(
        string $stripeClientSecret,
        bool $verifyWebhookSignature,
        bool $useLegacyStripeApi = true
    ) {
        $this->useLegacyStripeApi = $useLegacyStripeApi;
        $this->version = VersionService::getVersion();
        $this->verifyWebhookSignature = $verifyWebhookSignature;
        $this->setHttpClient(ApiRequestor::httpClient());
        $this->client = new StripeSdkClient([
            'api_key' => $stripeClientSecret,
            'stripe_version' => $useLegacyStripeApi ? self::LEGACY_API_VERSION : self::APP_API_VERSION,
            'app_info' => [
                'name' => self::APP_NAME,
                'version' => $this->version,
                'url' => self::APP_REPO,
                'partner_id' => self::APP_PARTNER_ID,
            ],
        ]);
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
        return $this->client->accounts->retrieve($accountId);
    }

    // returns Account []
    public function retrieveAllAccounts(): array
    {
        return iterator_to_array($this->client->accounts->all(['limit' => 50])->autoPagingIterator(), false);
    }

    public function createStripeAccount(int $shopId, array $details, array $metadata = []): Account
    {
        return $this->client->accounts->create(array_merge([
            'type' => 'express',
            'capabilities' => [
                'transfers' => ['requested' => true],
            ],
            'settings' => ['payouts' => [
                'debit_negative_balances' => false,
                'schedule' => ['interval' => 'manual'],
            ]],
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], $details));
    }

    // Account/Login Link
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl, string $type = 'account_onboarding'): AccountLink
    {
        return $this->client->accountLinks->create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => $type,
        ]);
    }

    public function createLoginLink(string $accountId): LoginLink
    {
        return $this->client->accounts->createLoginLink($accountId);
    }

    // Webhook Event
    public function webhookConstructEvent(string $payload, string $signatureHeader, string $webhookSecret): Event
    {
        if ($this->verifyWebhookSignature || $signatureHeader) {
            return Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);
        } else {
            $data = json_decode($payload, true);
            $jsonError = json_last_error();
            if (null === $data && JSON_ERROR_NONE !== $jsonError) {
                throw new UnexpectedValueException("Invalid payload: {$payload} (json_last_error() was {$jsonError})");
            }
            $data = (array) $data;

            return Event::constructFrom($data);
        }
    }

    public function setHttpClient(ClientInterface $client): void
    {
        ApiRequestor::setHttpClient($client instanceof StripeHttpClient ? $client : new StripeHttpClient($client));
    }

    public function isAmbiguousStripeFailure(ApiErrorException $exception): bool
    {
        $httpStatus = $exception->getHttpStatus();

        if (
            $exception instanceof ApiConnectionException
            || $exception instanceof IdempotencyException
            || $exception instanceof UnknownApiErrorException
            || null === $httpStatus
            || $httpStatus >= 500
            || 409 === $httpStatus
        ) {
            return true;
        }

        return !(
            $exception instanceof InvalidRequestException
            || $exception instanceof CardException
            || $exception instanceof AuthenticationException
            || $exception instanceof PermissionException
        );
    }

    // Transfer
    public function createTransfer(
        string $currency,
        int $amount,
        string $accountId,
        ?string $chargeId,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): Transfer {
        return Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $accountId,
            'source_transaction' => $chargeId,
        ], $this->idempotencyOptions($idempotencyKey));
    }

    // Transfer
    public function createTransferFromConnectedAccount(
        string $currency,
        int $amount,
        string $accountId,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): Transfer {
        $platformAccount = Account::retrieve();

        $options = ['stripe_account' => $accountId];
        if (null !== $idempotencyKey) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return Transfer::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $platformAccount->id,
        ], $options);
    }

    // Reversal
    public function reverseTransfer(
        int $amount,
        string $transferId,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): TransferReversal {
        return Transfer::createReversal($transferId, [
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], $this->idempotencyOptions($idempotencyKey));
    }

    // Payout
    public function createPayout(
        string $currency,
        int $amount,
        string $stripeAccountId,
        array $metadata = [],
        ?string $idempotencyKey = null
    ): Payout {
        $options = ['stripe_account' => $stripeAccountId];
        if (null !== $idempotencyKey) {
            $options['idempotency_key'] = $idempotencyKey;
        }

        return Payout::create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], $options);
    }

    // Refund
    public function createRefund(string $charge, ?int $amount, array $metadata = [], ?string $idempotencyKey = null): Refund
    {
        $params = [
            'charge' => $charge,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ];
        if (null !== $amount) {
            $params['amount'] = $amount;
        }

        $requestOptions = $idempotencyKey ? ['idempotency_key' => $idempotencyKey] : [];

        return $this->client->refunds->create($params, $requestOptions);
    }

    private function idempotencyOptions(?string $idempotencyKey): array
    {
        return null === $idempotencyKey ? [] : ['idempotency_key' => $idempotencyKey];
    }

    /**
     * @return Charge|PaymentIntent
     *
     * @throws ApiErrorException
     */
    public function capturePayment(string $paymentId, int $amount, ?string $idempotencyKey = null)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);

                return $pi->capture(['amount_to_capture' => $amount], $this->idempotencyOptions($idempotencyKey));
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->capturePayment($paymentIntentId, $amount, $idempotencyKey);
                }

                return $charge->capture(['amount' => $amount], $this->idempotencyOptions($idempotencyKey));
            default:
                throw new \Exception('Unexpected payment type: '.$paymentId);
        }
    }

    /**
     * @return Refund|PaymentIntent
     *
     * @throws ApiErrorException
     */
    public function cancelPayment(string $paymentId, ?string $idempotencyKey = null)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);
                $pi->cancel([], $this->idempotencyOptions($idempotencyKey));

                return $pi;
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->cancelPayment($paymentIntentId, $idempotencyKey);
                }

                return $this->createRefund($paymentId, null, [], $idempotencyKey);
            default:
                throw new \Exception('Unexpected payment type: '.$paymentId);
        }
    }

    // Payment intent
    public function paymentIntentRetrieve(string $stripePaymentIntentId): PaymentIntent
    {
        $params = $this->useLegacyStripeApi ? [] : ['expand' => ['latest_charge']];
        $paymentIntent = $this->client->paymentIntents->retrieve($stripePaymentIntentId, $params);
        if ($this->useLegacyStripeApi) {
            // Normalize the legacy response for payment validation, transfers, and refunds.
            $paymentIntent->latest_charge = $paymentIntent->charges->data[0] ?? null;
        }

        return $paymentIntent;
    }

    // Charge
    public function chargeRetrieve(string $stripeChargeId): Charge
    {
        return $this->client->charges->retrieve($stripeChargeId);
    }

    // Refund
    public function refundRetrieve(string $stripeRefundId): Refund
    {
        return Refund::retrieve($stripeRefundId);
    }
}
