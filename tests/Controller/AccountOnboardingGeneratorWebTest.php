<?php

namespace App\Tests\Controller;

use App\Factory\AccountOnboardingFactory;
use App\Security\TokenAuthenticator;
use App\Tests\ConnectorWebTestCase;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Component\HttpFoundation\Response;

class AccountOnboardingGeneratorWebTest extends ConnectorWebTestCase
{
    use RecreateDatabaseTrait;

    public function testUnauthorizedGenerateStripeOnboardingUrl()
    {
        $client = static::createUnauthenticatedClient();
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . MiraklMock::SHOP_BASIC . '}'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testForbiddenGenerateStripeOnboardingUrl()
    {
        $client = static::createUnauthenticatedClient([], [
            sprintf('HTTP_%s', TokenAuthenticator::AUTH_HEADER_NAME) => 'bad password',
        ]);
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . MiraklMock::SHOP_BASIC . '}'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testGenerateStripeOnboardingUrlWithBadId()
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . MiraklMock::SHOP_INVALID . '}'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGenerateStripeOnboardingUrlUnknownSeller()
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . MiraklMock::SHOP_NEW . '}'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($response->headers->contains('Content-Type', 'application/json'));
        $this->assertJson($response->getContent());
        $responseData = json_decode($response->getContent(), true);
        $this->assertStringContainsString(AccountOnboardingFactory::STRIPE_EXPRESS_BASE_URI, $responseData['redirect_url']);
    }

    public function testGenerateStripeOnboardingUrlWithBadPayload()
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId: 2000'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGenerateStripeOnboardingUrl()
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/api/onboarding',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"miraklShopId": ' . MiraklMock::SHOP_NEW . '}'
        );
        $response = $client->getResponse();
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($response->headers->contains('Content-Type', 'application/json'));
        $this->assertJson($response->getContent());
        $responseData = json_decode($response->getContent(), true);
        $this->assertStringContainsString(AccountOnboardingFactory::STRIPE_EXPRESS_BASE_URI, $responseData['redirect_url']);
    }
}
