<?php

namespace App\Service;

use Shivas\VersioningBundle\Service\VersionManagerInterface;
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
use Stripe\Stripe;
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

    /**
     * @var bool
     */
    private $verifyWebhookSignature;

    public const APP_NAME = 'MiraklConnector';
    public const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    public const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    public const APP_API_VERSION = '2019-08-14';

    public function __construct(
        VersionManagerInterface $versionManager,
        string $stripeClientSecret,
        bool $verifyWebhookSignature
    ) {
        $this->version = $versionManager->getVersion();
        $this->verifyWebhookSignature = $verifyWebhookSignature;
        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(self::APP_NAME, $this->version, self::APP_REPO, self::APP_PARTNER_ID);
        Stripe::setApiVersion(self::APP_API_VERSION);
    }

    private function getDefaultMetadata(): array
    {
        return [
            'pluginName' => self::APP_NAME,
            'pluginVersion' => $this->version
        ];
    }

    // Account
    public function retrieveAccount(string $accountId): Account
    {
        return Account::retrieve($accountId);
    }

    // returns Account []
    public function retrieveAllAccounts(): array
    {
        $hasmore = false;
        $connect_accounts = [];
        $lastConnectId = '';
        $limit = 50;
        do {
            $params = '' == $lastConnectId ? ['limit' => $limit] : ['limit' => $limit, 'starting_after' => $lastConnectId];
            $response = Account::all($params);
            $responseData = (array) $response['data'];
            $connect_accounts = array_merge($connect_accounts, $responseData);
            $hasmore = $response['has_more'];
            if (is_array($connect_accounts)) {
                $lastConnectId = end($connect_accounts)['id'];
            }
        } while ($hasmore);

        return $connect_accounts;
    }

    public function createStripeAccount(int $shopId, array $details, array $metadata = []): Account
    {
        return Account::create(array_merge([
            'type' => 'express',
            'settings' => ['payouts' => [
                'debit_negative_balances' => false,
                'schedule' => ['interval' => 'manual'],
            ]],
            'metadata' => array_merge($metadata, $this->getDefaultMetadata()),
        ], $details));
    }

    public function updateStripeAccount(string $stripeAccountId, array $details, array $metaData = []): Account
    {
        return Account::update($stripeAccountId, array_merge([
            'metadata' => array_merge($metaData, $this->getDefaultMetadata())
        ], $details));
    }

    // Account/Login Link
    public function createAccountLink(string $accountId, string $refreshUrl, string $returnUrl, string $type = 'account_onboarding'): AccountLink
    {
        return AccountLink::create([
            'account' => $accountId,
            'refresh_url' => $refreshUrl,
            'return_url' => $returnUrl,
            'type' => $type,
        ]);
    }

    public function createLoginLink(string $accountId): LoginLink
    {
        return Account::createLoginLink($accountId);
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
            'source_transaction' => $chargeId,
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
            'destination' => $platformAccount->id,
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
     * @return Charge|PaymentIntent
     *
     * @throws ApiErrorException
     */
    public function capturePayment(string $paymentId, int $amount)
    {
        switch (substr($paymentId, 0, 3)) {
            case 'pi_':
                $pi = PaymentIntent::constructFrom(['id' => $paymentId]);

                return $pi->capture(['amount_to_capture' => $amount]);
            case 'ch_':
            case 'py_':
                $charge = $this->chargeRetrieve($paymentId);

                // Check for PI
                $paymentIntentId = $charge->payment_intent ?? null;
                if ($paymentIntentId) {
                    return $this->capturePayment($paymentIntentId, $amount);
                }

                return $charge->capture(['amount' => $amount]);
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
                throw new \Exception('Unexpected payment type: '.$paymentId);
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
