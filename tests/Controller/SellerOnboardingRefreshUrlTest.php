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
        // Preserve the in-memory SQLite database across replay requests in one test.
        $this->client->disableReboot();
        $this->client->catchExceptions(false);
        $this->accountMappingRepository = static::getContainer()->get('doctrine')->getRepository(AccountMapping::class);
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
        $accountMapping->setOnboardingTokenExpiresAt(new \DateTimeImmutable('+24 hours'));

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
        $this->assertNull($this->accountMappingRepository->findOneByOnboardingToken('NotSubmittedAccount'));
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $this->executeRequest('NotSubmittedAccount')->getStatusCode());
    }

    public function testOnlyOneRequestCanConsumeAnAlreadyLoadedToken()
    {
        $mapping = $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_SUBMITTED, 'Concurrent');
        // Both requests have read the same credential before either consumes it.
        $secondRequestMapping = clone $mapping;

        $this->assertTrue($this->accountMappingRepository->consumeOnboardingToken($mapping, 'Concurrent'));
        $this->assertNull($mapping->getOnboardingToken());
        $this->assertNotNull($mapping->getOnboardingTokenExpiresAt());
        $this->assertFalse($this->accountMappingRepository->consumeOnboardingToken($secondRequestMapping, 'Concurrent'));

        // A stale request must not consume the replacement credential either.
        $mapping->setOnboardingToken('Replacement');
        $mapping->setOnboardingTokenExpiresAt(new \DateTimeImmutable('+24 hours'));
        $this->accountMappingRepository->persistAndFlush($mapping);
        $this->assertFalse($this->accountMappingRepository->consumeOnboardingToken($secondRequestMapping, 'Concurrent'));
        $this->assertNotNull($this->accountMappingRepository->findOneByOnboardingToken('Replacement'));
    }

    public function testExpiredAndLegacyTokensAreRejected()
    {
        $mapping = $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_SUBMITTED, 'Expired');
        foreach ([new \DateTimeImmutable('-1 second'), null] as $expiry) {
            $mapping->setOnboardingTokenExpiresAt($expiry);
            $this->accountMappingRepository->persistAndFlush($mapping);
            $response = $this->executeRequest('Expired');
            $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
            $this->assertEquals('Incorrect token', $response->getContent());
        }
    }

    public function testConsumedTokenCanBeRestoredAfterRefreshFailure()
    {
        $mapping = $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_SUBMITTED, 'Retry');
        $expiresAt = $mapping->getOnboardingTokenExpiresAt();

        $this->assertTrue($this->accountMappingRepository->consumeOnboardingToken($mapping, 'Retry'));
        $this->accountMappingRepository->restoreOnboardingToken($mapping, 'Retry', $expiresAt);

        $this->assertEquals('Retry', $mapping->getOnboardingToken());
        $this->assertEquals($expiresAt->getTimestamp(), $mapping->getOnboardingTokenExpiresAt()->getTimestamp());
        $this->assertTrue($this->executeRequest('Retry')->isRedirect());
    }

    public function testValidTokenSubmittedAccount()
    {
        $accountMapping = $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NEW, 'SubmittedAccount');
        $response = $this->executeRequest('SubmittedAccount');
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Onboarding already completed.', $response->getContent());
        $this->assertNull($this->accountMappingRepository->findOneByStripeAccountId($accountMapping->getStripeAccountId())->getOnboardingToken());
    }
}
