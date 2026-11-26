<?php

namespace App\Service;

use Stripe\Account;
use Stripe\AccountLink;
use Stripe\ApiRequestor;
use Stripe\Charge;
use Stripe\Event;
use Stripe\Exception\ApiErrorException;
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

    // Transfer
    public function createTransfer(string $currency, int $amount, string $accountId, ?string $chargeId, array $metadata = []): Transfer
    {
        return $this->client->transfers->create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $accountId,
            'source_transaction' => $chargeId,
        ]);
    }

    // Transfer
    public function createTransferFromConnectedAccount(string $currency, int $amount, string $accountId, array $metadata = []): Transfer
    {
        $platformAccount = $this->client->accounts->retrieve();

        return $this->client->transfers->create([
            'currency' => $currency,
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
            'destination' => $platformAccount->id,
        ], ['stripe_account' => $accountId]);
    }

    // Reversal
    public function reverseTransfer(int $amount, string $transferId, array $metadata = []): TransferReversal
    {
        return $this->client->transfers->createReversal($transferId, [
            'amount' => $amount,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ]);
    }

    // Payout
    public function createPayout(string $currency, int $amount, string $stripeAccountId, array $metadata = []): Payout
    {
        return $this->client->payouts->create([
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
        $params = [
            'charge' => $charge,
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ];
        if (null !== $amount) {
            $params['amount'] = $amount;
        }

        return $this->client->refunds->create($params);
    }

    /**
     * @return Charge|PaymentIntent
     *
     * @throws ApiErrorException
     */
    public function capturePayment(string $paymentId, int $amount)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                return $this->client->paymentIntents->capture($paymentId, ['amount_to_capture' => $amount]);
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->capturePayment($paymentIntentId, $amount);
                }

                return $this->client->charges->capture($paymentId, ['amount' => $amount]);
            default:
                throw new \Exception('Unexpected payment type: '.$paymentId);
        }
    }

    /**
     * @return Refund|PaymentIntent
     *
     * @throws ApiErrorException
     */
    public function cancelPayment(string $paymentId)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                return $this->client->paymentIntents->cancel($paymentId);
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
}
