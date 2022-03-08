<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Service\MiraklClient;
use App\Service\SellerOnboardingService;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Exception\ClientException;

class SellerOnboardingServiceTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->miraklClient = self::$container->get('App\Service\MiraklClient');
        $this->sellerOnboardingService = self::$container->get('App\Service\SellerOnboardingService');
        $this->accountMappingRepository = self::$container->get('doctrine')->getRepository(AccountMapping::class);
    }

    private function getAccountMappingsFromRepository()
    {
        return $this->accountMappingRepository->findAll();
    }

    private function deleteAllAccountMappingsFromRepository()
    {
        foreach ($this->accountMappingRepository->findAll() as $accountMapping) {
            $this->accountMappingRepository->removeAndFlush($accountMapping);
        }
    }

    private function mockAccountMapping(int $shopId, string $accountId, bool $payinsEnabled = false, bool $payoutsEnabled = false, ?string $disableReason = null)
    {
        $accountMapping = new AccountMapping();
        $accountMapping->setMiraklShopId($shopId);
        $accountMapping->setStripeAccountId($accountId);
        $accountMapping->setPayinEnabled($payinsEnabled);
        $accountMapping->setPayoutEnabled($payoutsEnabled);
        $accountMapping->setDisabledReason($disableReason);

        $this->accountMappingRepository->persistAndFlush($accountMapping);

        return $accountMapping;
    }

    public function testGetExistingAccountMapping()
    {
        $accountMappingsCount = count($this->getAccountMappingsFromRepository());
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop(
            current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_BASIC]))
        );
        $this->assertInstanceOf(AccountMapping::class, $accountMapping);
        $this->assertCount($accountMappingsCount, $this->getAccountMappingsFromRepository());
    }

    public function testGetNewAccountMapping()
    {
        $accountMappingsCount = count($this->getAccountMappingsFromRepository());
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop(
            current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_NEW]))
        );
        $this->assertInstanceOf(AccountMapping::class, $accountMapping);
        $this->assertCount($accountMappingsCount + 1, $this->getAccountMappingsFromRepository());
    }

    public function testGetNewAccountMappingStripeError()
    {
        $this->expectException(ApiErrorException::class);
        $this->sellerOnboardingService->getAccountMappingFromShop(
            current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_STRIPE_ERROR]))
        );
    }

    public function testUpdateCustomField()
    {
        $shop = current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_NEW]));
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop($shop);
        $url = $this->sellerOnboardingService->addOnboardingLinkToShop($shop->getId(), $accountMapping);
        $this->assertNotEmpty($url);
    }

    public function testUpdateCustomFieldAlreadyFilledWithOauthUrl()
    {
        $shop = current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_WITH_OAUTH_URL]));
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop($shop);
        $url = $this->sellerOnboardingService->addOnboardingLinkToShop($shop->getId(), $accountMapping);
        $this->assertNotEmpty($url);
    }

    public function testUpdateCustomFieldWithStripeError()
    {
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_FOUND);
        $shop = current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_NEW]));
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop($shop);
        $this->expectException(ApiErrorException::class);
        $this->sellerOnboardingService->addOnboardingLinkToShop($shop->getId(), $accountMapping);
    }

    public function testUpdateCustomFieldWithMiraklError()
    {
        $shop = current($this->miraklClient->listShopsByIds([MiraklMock::SHOP_MIRAKL_ERROR]));
        $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop($shop);
        $this->expectException(ClientException::class);
        $this->sellerOnboardingService->addOnboardingLinkToShop($shop->getId(), $accountMapping);
    }
}
