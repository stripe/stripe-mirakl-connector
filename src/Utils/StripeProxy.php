<?php

namespace App\Utils;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Shivas\VersioningBundle\Service\VersionManager;
use Stripe\Account;
use Stripe\Event;
use Stripe\HttpClient\ClientInterface;
use Stripe\LoginLink;
use Stripe\Stripe;
use Stripe\StripeObject;

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
    private $webhookSecret;

    private const APP_NAME = 'MiraklConnector';
    private const APP_REPO = 'https://github.com/stripe/stripe-mirakl-connector';
    private const APP_PARTNER_ID = 'pp_partner_FuvjRG4UuotFXS';
    private const APP_API_VERSION = '2019-08-14';

    /**
     * @var string
     */
    private $version;

    public function __construct(string $stripeClientSecret, string $webhookSecret, VersionManager $versionManager)
    {
        $this->version = $versionManager->getVersion();

        Stripe::setApiKey($stripeClientSecret);
        Stripe::setAppInfo(self::APP_NAME, $this->version, self::APP_REPO, self::APP_PARTNER_ID);
        Stripe::setApiVersion(self::APP_API_VERSION);

        $this->webhookSecret = $webhookSecret;
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
    public function webhookConstructEvent(string $payload, string $signatureHeader): Event
    {
        return \Stripe\Webhook::constructEvent(
            $payload,
            $signatureHeader,
            $this->webhookSecret
        );
    }

    public function setHttpClient(ClientInterface $client)
    {
        \Stripe\ApiRequestor::setHttpClient($client);
    }

    // Refund
    public function createRefund(int $amount, ?string $transactionId, array $metadata = [])
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $params = [
            'charge' => $transactionId,
            'amount' => $amount,
            'metadata' => $mergedMetadata,
            'reverse_transfer' => false
        ];

        $this->logger->info('[Stripe API] Call to \Stripe\Refund::create', $params);

        return \Stripe\Refund::create($params);
    }

    public function listRefunds(?string $transactionId) 
    {
        return \Stripe\Charge::retrieve($transactionId)->refunds;
    }

    public function reverseTransfer(int $amount, ?string $transfer_id, array $metadata = [])
    {
        $mergedMetadata = array_merge($metadata, $this->getDefaultMetadata());
        $params = [
            'amount' => $amount,
            'metadata' => $mergedMetadata
        ];
        return \Stripe\Transfer::createReversal($transfer_id, $params);
    }

    public function listReversals(?string $transfer_id)
    {
        return \Stripe\Transfer::retrieve($transfer_id)->reversals;
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
}
