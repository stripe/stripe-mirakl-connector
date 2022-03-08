<?php

namespace App\Tests\Controller;

use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Security\TokenAuthenticator;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class AccountMappingByOperatorTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var KernelBrowser
     */
    protected $client;

    /**
     * @var AccountMappingRepository
     */
    protected $accountMappingRepository;

    protected function setUp(): void
    {
        $this->client =  self::createClient();
        $this->accountMappingRepository = self::$container->get('doctrine')->getRepository(AccountMapping::class);
    }

    private function executeRequest(string $payload, ?string $authenticate = 'operator-test')
    {
        $server = $authenticate ? ['HTTP_' . TokenAuthenticator::AUTH_HEADER_NAME => $authenticate] : [];
        $this->client->request('POST', '/api/mappings', [], [], $server, $payload);
        return $this->client->getResponse();
    }

    public function testMissingAuthentication()
    {
        $response = $this->executeRequest("{}", null);
        $this->assertEquals('{"message":"Authentication Required"}', $response->getContent());
        $this->assertEquals(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
    }

    public function testBadAuthentication()
    {
        $response = $this->executeRequest("{}", 'Bad password');
        $this->assertEquals('{"message":"Invalid credentials."}', $response->getContent());
        $this->assertEquals(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    public function testInvalidPayload()
    {
        $response = $this->executeRequest("Invalid");
        $this->assertEquals('Invalid JSON format', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testInvalidAccountId()
    {
        $shopId = MiraklMock::SHOP_NEW;
        $accountId = StripeMock::ACCOUNT_NOT_FOUND;
        $response = $this->executeRequest(<<<PAYLOAD
        {
            "miraklShopId": $shopId,
            "stripeUserId": "$accountId"
        }
        PAYLOAD);
        $this->assertEquals('Cannot find the Stripe account corresponding to this stripe Id', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testInvalidShopId()
    {
        $shopId = MiraklMock::SHOP_INVALID;
        $accountId = StripeMock::ACCOUNT_NEW;
        $response = $this->executeRequest(<<<PAYLOAD
        {
            "miraklShopId": $shopId,
            "stripeUserId": "$accountId"
        }
        PAYLOAD);
        $this->assertEquals('Invalid Mirakl Shop ID', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testCreateMappingForEnabledAccount()
    {
        $shopId = MiraklMock::SHOP_NEW;
        $accountId = StripeMock::ACCOUNT_NEW;
        $response = $this->executeRequest(<<<PAYLOAD
        {
            "miraklShopId": $shopId,
            "stripeUserId": "$accountId"
        }
        PAYLOAD);
        $this->assertEquals('Mirakl - Stripe mapping created', $response->getContent());
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($accountId);
        $this->assertEquals(true, $accountMapping->getPayinEnabled());
        $this->assertEquals(true, $accountMapping->getPayoutEnabled());
        $this->assertNull($accountMapping->getDisabledReason());
    }

    public function testShopIdAlreadyMapped()
    {
        $shopId = MiraklMock::SHOP_BASIC;
        $accountId = StripeMock::ACCOUNT_NEW;
        $response = $this->executeRequest(<<<PAYLOAD
        {
            "miraklShopId": $shopId,
            "stripeUserId": "$accountId"
        }
        PAYLOAD);
        $this->assertEquals('The provided Mirakl Shop ID or Stripe User Id is already mapped', $response->getContent());
        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testAccountIdAlreadyMapped()
    {
        $shopId = MiraklMock::SHOP_NEW;
        $accountId = StripeMock::ACCOUNT_BASIC;
        $response = $this->executeRequest(<<<PAYLOAD
        {
            "miraklShopId": $shopId,
            "stripeUserId": "$accountId"
        }
        PAYLOAD);
        $this->assertEquals('The provided Mirakl Shop ID or Stripe User Id is already mapped', $response->getContent());
        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }
}
