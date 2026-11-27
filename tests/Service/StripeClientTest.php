<?php

namespace App\Tests\Service;

use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Stripe\ApiRequestor;
use Stripe\Charge;
use Stripe\Exception\InvalidRequestException;
use Stripe\HttpClient\ClientInterface;
use Stripe\HttpClient\CurlClient;
use Stripe\Util\CaseInsensitiveArray;

class StripeClientTest extends TestCase
{
    private $previousHttpClient;

    protected function setUp(): void
    {
        $this->previousHttpClient = CurlClient::instance();
    }

    protected function tearDown(): void
    {
        ApiRequestor::setHttpClient($this->previousHttpClient);
    }

    private function clientWithResponse(callable $check, array $response, ?bool $useLegacyStripeApi = false, int $status = 200, array $responseHeaders = []): StripeClient
    {
        $http = $this->createMock(ClientInterface::class);
        $http->expects(self::once())->method('request')->willReturnCallback(
            function ($method, $url, $headers, $params, $hasFile) use ($check, $response, $useLegacyStripeApi, $status, $responseHeaders) {
                self::assertContains('Stripe-Version: '.(($useLegacyStripeApi ?? true) ? StripeClient::LEGACY_API_VERSION : StripeClient::APP_API_VERSION), $headers);
                $check($method, parse_url($url, PHP_URL_PATH), $headers, $params);

                return [json_encode($response), $status, new CaseInsensitiveArray($responseHeaders)];
            }
        );
        $client = null === $useLegacyStripeApi
            ? new StripeClient('sk_test_connector', false)
            : new StripeClient('sk_test_connector', false, $useLegacyStripeApi);
        $client->setHttpClient($http);

        return $client;
    }

    public function testRetrievalExpandsLatestChargeAndUsesPinnedApi(): void
    {
        $client = $this->clientWithResponse(function ($method, $path, $headers, $params) {
            self::assertSame('get', $method);
            self::assertSame('/v1/payment_intents/pi_test', $path);
            self::assertSame(['expand' => ['latest_charge']], $params);
        }, ['id' => 'pi_test', 'object' => 'payment_intent', 'latest_charge' => ['id' => 'ch_test', 'object' => 'charge']]);

        self::assertInstanceOf(Charge::class, $client->paymentIntentRetrieve('pi_test')->latest_charge);
    }

    /** @dataProvider legacyCharges */
    public function testLegacyResponseIsNormalized(array $charges, ?string $expectedId): void
    {
        $client = $this->clientWithResponse(function ($method, $path, $headers, $params) {
            self::assertArrayNotHasKey('expand', $params);
        }, ['id' => 'pi_test', 'object' => 'payment_intent', 'charges' => [
            'object' => 'list', 'data' => $charges, 'has_more' => false,
        ]], null);

        $charge = $client->paymentIntentRetrieve('pi_test')->latest_charge;
        self::assertSame($expectedId, $charge->id ?? null);
    }

    public static function legacyCharges(): array
    {
        return [
            'captured' => [[['id' => 'ch_test', 'object' => 'charge']], 'ch_test'],
            'missing' => [[], null],
        ];
    }

    public function testFullRefundOmitsAmount(): void
    {
        $client = $this->clientWithResponse(function ($method, $path, $headers, $params) {
            self::assertSame('post', $method);
            self::assertSame('/v1/refunds', $path);
            self::assertSame('ch_test', $params['charge']);
            self::assertArrayNotHasKey('amount', $params);
        }, ['id' => 're_test', 'object' => 'refund']);
        self::assertSame('re_test', $client->createRefund('ch_test', null)->id);
    }

    public function testConnectedPayoutIsExplicitlyScoped(): void
    {
        $client = $this->clientWithResponse(function ($method, $path, $headers, $params) {
            self::assertSame('/v1/payouts', $path);
            self::assertContains('Stripe-Account: acct_seller', $headers);
            self::assertSame(100, $params['amount']);
        }, ['id' => 'po_test', 'object' => 'payout']);
        self::assertSame('po_test', $client->createPayout('eur', 100, 'acct_seller')->id);
    }

    public function testAccountCreationRequestsTransfersAndManualPayouts(): void
    {
        $client = $this->clientWithResponse(function ($method, $path, $headers, $params) {
            self::assertSame('/v1/accounts', $path);
            self::assertSame('true', $params['capabilities']['transfers']['requested']);
            self::assertSame('manual', $params['settings']['payouts']['schedule']['interval']);
            self::assertSame('express', $params['type']);
        }, ['id' => 'acct_test', 'object' => 'account']);
        self::assertSame('acct_test', $client->createStripeAccount(1, [])->id);
    }

    public function testEmptyAccountList(): void
    {
        $client = $this->clientWithResponse(function ($method, $path) {
            self::assertSame('/v1/accounts', $path);
        }, ['object' => 'list', 'url' => '/v1/accounts', 'data' => [], 'has_more' => false]);
        self::assertSame([], $client->retrieveAllAccounts());
    }

    public function testLegacyAccountCreationSuppressesStripeNotice(): void
    {
        $client = $this->clientWithResponse(function ($method, $path) {
            self::assertSame('post', $method);
            self::assertSame('/v1/accounts', $path);
        }, ['id' => 'acct_test', 'object' => 'account'], null, 200, [
            'Stripe-Notice' => 'We recommend building your integration using Accounts v2.',
            'Request-Id' => 'req_test',
        ]);

        $account = $client->createStripeAccount(1, []);

        self::assertSame('acct_test', $account->id);
        self::assertFalse(isset($account->getLastResponse()->headers['stripe-notice']));
        self::assertSame('req_test', $account->getLastResponse()->headers['request-id']);
    }

    public function testStripeApiErrorStillPropagatesWithNoticePresent(): void
    {
        $client = $this->clientWithResponse(function ($method, $path) {
            self::assertSame('/v1/accounts/acct_test', $path);
        }, ['error' => ['type' => 'invalid_request_error', 'message' => 'Account not found.']], null, 400, [
            'Stripe-Notice' => 'We recommend building your integration using Accounts v2.',
        ]);

        $this->expectException(InvalidRequestException::class);
        $this->expectExceptionMessage('Account not found.');
        $client->retrieveAccount('acct_test');
    }
}
