<?php

namespace App\Tests\Controller;

use App\Tests\StripeWebTestCase;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Component\HttpFoundation\Response;

/**
 * @group integration
 */
class CreateMiraklStripeAccountMappingIntegrationTest extends StripeWebTestCase
{
    use RecreateDatabaseTrait;

    protected $mockedStripeClient;

    public function testGenerateMapping()
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": 11, "stripeUserId": "acct_12345"}'
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

        $client->request(
            'POST',
            '/api/mappings',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": 13, "stripeUserId": "acct_1"}'
        );

        $response = $client->getResponse();

        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }
}
