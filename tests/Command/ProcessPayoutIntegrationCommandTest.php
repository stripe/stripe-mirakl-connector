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
        $commandTester->execute(['command' => $this->command->getName()]);

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

        $stripePayout = $this->getPayoutByInvoiceId(4);
        $this->assertEquals(1234, $stripePayout->getAmount());

        $expectedAmounts = [
            StripeTransfer::TRANSFER_SUBSCRIPTION => 999,
            StripeTransfer::TRANSFER_EXTRA_CREDITS => 5678,
            StripeTransfer::TRANSFER_EXTRA_INVOICES => 9876,
        ];
        foreach ($expectedAmounts as $type => $expectedAmount) {
            $stripeTransfer = $this->getTransferByInvoiceIdAndType(4, $type);
            $this->assertEquals($expectedAmount, $stripeTransfer->getAmount());
        }
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
        $retriedStripePayout = $this->getPayoutByInvoiceId(6);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $retriedStripePayout->getStatus());

        // Subsequent payout should not have failed
        $subsequentStripePayout = $this->getPayoutByInvoiceId(7);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $subsequentStripePayout->getStatus());

        // Two messages should be sent
        $this->assertCount(2, $this->payoutsReceiver->getSent());
    }

    public function testAlreadyCreatedPayout()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_shop_id' => '3',
        ]);

        // Created payout shouldn't be retried
        $createdStripePayout = $this->getPayoutByInvoiceId(8);
        $this->assertEquals(StripePayout::PAYOUT_CREATED, $createdStripePayout->getStatus());

        // Subsequent payout should not have failed
        $subsequentStripePayout = $this->getPayoutByInvoiceId(9);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $subsequentStripePayout->getStatus());

        // Only one message should be sent
        $this->assertCount(1, $this->payoutsReceiver->getSent());
    }

    public function testAlreadyCreatedPayoutAndFailedTransfer()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_shop_id' => '4',
        ]);

        // Created payout shouldn't be retried
        $createdStripePayout = $this->getPayoutByInvoiceId(10);
        $this->assertEquals(StripePayout::PAYOUT_CREATED, $createdStripePayout->getStatus());

        // Failed transfer should be retried
        $failedStripeTransfer = $this->getTransferByInvoiceIdAndType(10, StripeTransfer::TRANSFER_SUBSCRIPTION);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $failedStripeTransfer->getStatus());

        // Subsequent payout should not have failed
        $newStripePayout = $this->getPayoutByInvoiceId(11);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $newStripePayout->getStatus());

        // Only one payout message should be sent
        $this->assertCount(1, $this->payoutsReceiver->getSent());

        // 6 transfer messages should be sent
        $this->assertCount(6, $this->transfersReceiver->getSent());
    }

    private function getPayoutByInvoiceId($invoiceId): StripePayout
    {
        $query = ['miraklInvoiceId' => $invoiceId];
        return $this->stripePayoutRepository->findOneBy($query);
    }

    private function getTransfersByInvoiceId($invoiceId): array
    {
        $query = ['miraklId' => $invoiceId];
        return $this->stripeTransferRepository->findBy($query);
    }

    private function getTransferByInvoiceIdAndType($invoiceId, $type): StripeTransfer
    {
        $query = ['miraklId' => $invoiceId, 'type' => $type];
        return $this->stripeTransferRepository->findOneBy($query);
    }
}
