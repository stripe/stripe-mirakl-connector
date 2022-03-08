<?php

namespace App\Tests\Command;

use App\Entity\AccountMapping;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Monolog\Handler\StreamHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SellerOnboardingCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var StreamHandler
     */
    protected $testHandler;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var AccountMappingRepository
     */
    protected $accountMappingRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $this->command = $application->find('connector:sync:onboarding');
        $this->commandTester = new CommandTester($this->command);

        $this->configService = self::$container->get('App\Service\ConfigService');
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

    private function mockAccountMapping(int $shopId, string $accountId = StripeMock::ACCOUNT_BASIC, bool $payinsEnabled = false, bool $payoutsEnabled = false, ?string $disableReason = null)
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

    private function executeCommand()
    {
        $this->commandTester->execute(['command' => $this->command->getName()]);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testFirstExecution()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->configService->setSellerOnboardingCheckpoint(null);
        $this->executeCommand();
        $this->assertCount(0, $this->getAccountMappingsFromRepository());
    }

    public function testNewShop()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_NEW);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNotNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }

    public function testStripeError()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_STRIPE_ERROR);
        $this->executeCommand();
        $this->assertCount(0, $this->getAccountMappingsFromRepository());
    }

    public function testMiraklError()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_MIRAKL_ERROR);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }

    public function testExistingShopWithoutUrl()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->mockAccountMapping(MiraklMock::SHOP_BASIC);
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_EXISTING_WITHOUT_URL);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNotNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }

    public function testExistingShopWithUrl()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->mockAccountMapping(MiraklMock::SHOP_WITH_URL);
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_EXISTING_WITH_URL);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }

    public function testExistingShopWithOauthUrl()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->mockAccountMapping(MiraklMock::SHOP_WITH_OAUTH_URL);
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_EXISTING_WITH_OAUTH_URL);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNotNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }

    public function testExistingShopWithStripeError()
    {
        $this->deleteAllAccountMappingsFromRepository();
        $this->mockAccountMapping(MiraklMock::SHOP_BASIC, StripeMock::ACCOUNT_NOT_FOUND);
        $this->configService->setSellerOnboardingCheckpoint(MiraklMock::SHOP_DATE_1_EXISTING_WITHOUT_URL);
        $this->executeCommand();
        $this->assertCount(1, $this->getAccountMappingsFromRepository());
        $this->assertNull(current($this->getAccountMappingsFromRepository())->getOnboardingToken());
    }
}
