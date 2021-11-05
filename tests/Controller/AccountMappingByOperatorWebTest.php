<?php

namespace App\Tests\Controller;

use App\Tests\ConnectorWebTestCase;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Component\HttpFoundation\Response;

class AccountMappingByOperatorWebTest extends ConnectorWebTestCase
{
    use RecreateDatabaseTrait;

    protected $mockedStripeClient;

    public function testGenerateMapping()
    {
        $client = static::createClient();

        $miraklShopId = MiraklMock::SHOP_NEW;
        $stripeUserId = StripeMock::ACCOUNT_NEW;
        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . $miraklShopId . ', "stripeUserId": "' . $stripeUserId . '"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
    }

    public function testGenerateMappingWithBadPayload()
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId: 11, "stripeUserId": "acct_12345"'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBadStripeIdMapping()
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": 12, "stripeUserId": "54321"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testBadMiraklIdFormatMapping()
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": 9999, "stripeUserId": "acct_12345"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testAlreadyExistingMiraklIdMapping()
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": 1, "stripeUserId": "acct_12345"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testAlreadyExistingStripeIdMapping()
    {
        $client = static::createClient();

        $stripeUserId = StripeMock::ACCOUNT_BASIC;
        $miraklShopId = MiraklMock::SHOP_BASIC;
        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . $miraklShopId . ', "stripeUserId": "' . $stripeUserId . '"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }
}
