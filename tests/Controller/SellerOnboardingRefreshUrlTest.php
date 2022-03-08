<?php

namespace App\Tests\Controller;

use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class SellerOnboardingRefreshUrlTest extends WebTestCase
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

    private function executeRequest(?string $token = null)
    {
        $this->client->request('GET', '/api/public/onboarding/refresh', $token ? ['token' => $token] : []);
        return $this->client->getResponse();
    }

    private function mockAccountMapping(int $shopId, string $accountId, string $token)
    {
        $accountMapping = new AccountMapping();
        $accountMapping->setMiraklShopId($shopId);
        $accountMapping->setStripeAccountId($accountId);
        $accountMapping->setOnboardingToken($token);

        $this->accountMappingRepository->persistAndFlush($accountMapping);

        return $accountMapping;
    }

    public function testMissingToken()
    {
        $response = $this->executeRequest();
        $this->assertEquals('Incorrect token', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testInvalidToken()
    {
        $response = $this->executeRequest('Invalid');
        $this->assertEquals('Incorrect token', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testValidTokenUnsubmittedAccount()
    {
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_SUBMITTED, 'NotSubmittedAccount');
        $response = $this->executeRequest('NotSubmittedAccount');
        $this->assertTrue($response->isRedirect());
        $this->assertEquals('https://connect.stripe.com/setup/s/mov7fZc0o4Yx', $response->headers->get('Location'));
    }

    public function testValidTokenSubmittedAccount()
    {
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NEW, 'SubmittedAccount');
        $response = $this->executeRequest('SubmittedAccount');
        $this->assertTrue($response->isRedirect());
        $this->assertEquals('https://connect.stripe.com/express/SgETLzuPbZVg', $response->headers->get('Location'));
    }
}
