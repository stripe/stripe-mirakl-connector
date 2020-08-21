<?php

namespace App\Tests\Command;

use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class ProcessPayoutIntegrationCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-payout');

        $this->payoutsReceiver = self::$container->get('messenger.transport.process_payouts');
        $this->transfersReceiver = self::$container->get('messenger.transport.process_transfers');

        $this->stripeTransferRepository = self::$container->get('doctrine')->getRepository(StripeTransfer::class);
        $this->stripePayoutRepository = self::$container->get('doctrine')->getRepository(StripePayout::class);
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $stripePayoutsPending = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_PENDING
        ]);
        $this->assertEquals(0, $commandTester->getStatusCode());

        $this->assertCount(1, $this->payoutsReceiver->getSent());
        $this->assertCount(3, $this->transfersReceiver->getSent());

        $this->assertEquals(4, count($stripePayoutsPending));
        $this->assertEquals(6, count($stripeTransfersPending));
    }

    public function testExecuteWithArguments()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_shop_id' => '1',
        ]);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $stripePayoutsPending = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_PENDING
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        $this->assertCount(1, $this->payoutsReceiver->getSent());
        $this->assertCount(3, $this->transfersReceiver->getSent());

        $this->assertEquals(4, count($stripePayoutsPending));
        $this->assertEquals(6, count($stripeTransfersPending));
    }

    public function testRetryFailedPayout()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_shop_id' => '2',
        ]);

        // Failed payout should be retried
        $retriedStripePayout = $this->stripePayoutRepository->findOneBy([
            'miraklId' => 6
        ]);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $retriedStripePayout->getStatus());

        // Subsequent transfer should not have failed (no "EntityManager closed" error)
        $subsequentStripePayout = $this->stripePayoutRepository->findOneBy([
            'miraklId' => 7
        ]);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $subsequentStripePayout->getStatus());

        // Two messages should be sent
        $this->assertCount(2, $this->doctrineReceiver->getSent());
    }
}
