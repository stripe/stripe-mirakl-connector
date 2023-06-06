<?php

namespace App\Tests\Command;

use App\Entity\AccountMapping;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Monolog\Handler\StreamHandler;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\MailerInterface;
use Stripe\Account;
use Stripe\Collection;
class SellerMonitorKYCStatusCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var StreamHandler
     */
    protected $testHandler;

    /**
     * @var MailerInterface
     */
    private $mailer;
    

    /**
     * @var MiraklClient
     */
    private $miraklClient;

     /**
     * @var StripeClient
     */
    private $stripeClient;

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
        $this->miraklClient = $this->createMock(MiraklClient::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->accountMappingRepository = self::$container->get('doctrine')->getRepository(AccountMapping::class);
        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:monitor-kyc-status');
        $this->command->setStripeClient($this->stripeClient);
        $this->command->setAccountMappingRepository($this->accountMappingRepository);
        $this->command->setTechnicalEmailFrom("test@stripe.com");
        $this->command->setTechnicalEmail("test@stripe.com");
        $this->command->setAccountMappingRepository($this->accountMappingRepository);
        $this->command->setMailer($this->mailer );

        $this->commandTester = new CommandTester($this->command);

        //$this->configService = self::$container->get('App\Service\ConfigService')
    }

    



    private function executeCommand()
    {
        $this->commandTester->execute(['command' => $this->command->getName()]);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    public function testFirstExecution()
    {
       
        $stripeAccount = new Account('1');
        $curre_due =[];
        $stripeAccount->requirements = array("currently_due" => $curre_due,"pending_verification"=>[],"disabled_reason"=>"TEST_KYC");
      //  $stripeAccount->payouts_enabled = $payoutEnabled;
      //  $stripeAccount->charges_enabled = $chargesEnabled;
        $this->mockAccountMapping("1000","1");
        $connect_accounts = array();
        $connect_accounts[] =  $stripeAccount;
        $this->stripeClient
            ->expects($this->once())
            ->method('retrieveAllAccounts')
            ->willReturn($connect_accounts);


            $this
            ->mailer
            ->expects($this->once())
            ->method('send');



            
        
        $returncode = $this->executeCommand();
        $this->assertEquals(0, $returncode);
    }



    public function testNegativeCaseExecution()
    {
       
        $stripeAccount = new Account('1');
        $curre_due =[];
        $stripeAccount->requirements = array("currently_due" => $curre_due,"pending_verification"=>[],"disabled_reason"=>"");
      //  $stripeAccount->payouts_enabled = $payoutEnabled;
      //  $stripeAccount->charges_enabled = $chargesEnabled;
        $this->mockAccountMapping("1000","1");
        $connect_accounts = array();
        $connect_accounts[] =  $stripeAccount;
        $this->stripeClient
            ->expects($this->once())
            ->method('retrieveAllAccounts')
            ->willReturn($connect_accounts);
        
            $this
            ->mailer
            ->expects($this->never())
            ->method('send');
        
        $returncode = $this->executeCommand();
        $this->assertEquals(0, $returncode);
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


 
}
