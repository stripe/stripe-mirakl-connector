<?php

namespace App\Tests\Command;

use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\TransportInterface;

class SellerSettlementCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var StripeTransferRepository
     */
    protected $stripeTransferRepository;

    /**
     * @var StripePayoutRepository
     */
    protected $stripePayoutRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-payout');
        $this->commandTester = new CommandTester($this->command);

        $this->transfersReceiver = self::$container->get('messenger.transport.process_transfers');
        $this->payoutsReceiver = self::$container->get('messenger.transport.process_payouts');
        $this->configService = self::$container->get('App\Service\ConfigService');
        $this->stripeTransferRepository = self::$container->get('doctrine')->getRepository(StripeTransfer::class);
        $this->stripePayoutRepository = self::$container->get('doctrine')->getRepository(StripePayout::class);
    }

    private function executeCommand($arguments = null)
    {
        $this->transfersReceiver->reset();
        $this->payoutsReceiver->reset();
        $this->commandTester->execute(array_merge([
            'command' => $this->command->getName()
        ], $arguments ?? []));
        $this->assertEquals(0, $this->commandTester->getStatusCode());
    }

    private function mockOnHoldTransfer(StripeTransfer $transfer)
    {
        $transfer->setTransferId(null);
        $transfer->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
        $transfer->setStatusReason('reason');
        $this->stripeTransferRepository->flush();
    }

    private function mockFailedTransfer(StripeTransfer $transfer)
    {
        $transfer->setTransferId(null);
        $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
        $transfer->setStatusReason('reason');
        $this->stripeTransferRepository->flush();
    }

    private function mockCreatedTransfer(StripeTransfer $transfer)
    {
        $transfer->setTransferId(StripeMock::TRANSFER_BASIC);
        $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $transfer->setStatusReason(null);
        $this->stripeTransferRepository->flush();
    }

    private function mockOnHoldPayout(StripePayout $payout)
    {
        $payout->setPayoutId(null);
        $payout->setStatus(StripePayout::PAYOUT_ON_HOLD);
        $payout->setStatusReason('reason');
        $this->stripePayoutRepository->flush();
    }

    private function mockFailedPayout(StripePayout $payout)
    {
        $payout->setPayoutId(null);
        $payout->setStatus(StripePayout::PAYOUT_FAILED);
        $payout->setStatusReason('reason');
        $this->stripePayoutRepository->flush();
    }

    private function mockCreatedPayout(StripePayout $payout)
    {
        $payout->setPayoutId(StripeMock::PAYOUT_BASIC);
        $payout->setStatus(StripePayout::PAYOUT_CREATED);
        $payout->setStatusReason('null');
        $this->stripePayoutRepository->flush();
    }

    private function getTransfersFromRepository()
    {
        return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::getInvoiceTypes()
        ]);
    }

    private function getPayoutsFromRepository()
    {
        return $this->stripePayoutRepository->findAll();
    }

    public function testByShopId()
    {
        // 1 invoice with all amounts
        $this->executeCommand(['mirakl_shop_id' => MiraklMock::INVOICE_SHOP_BASIC]);

        // 1 payout and 3 transfers dispatched
        $this->assertCount(3, $this->transfersReceiver->getSent());
        $this->assertCount(3, $this->getTransfersFromRepository());
        $this->assertCount(1, $this->payoutsReceiver->getSent());
        $this->assertCount(1, $this->getPayoutsFromRepository());

        // 1 invoice with payout amount only
        $this->executeCommand(['mirakl_shop_id' => MiraklMock::INVOICE_SHOP_PAYOUT_ONLY]);

        // Only the payout is dispatched
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(6, $this->getTransfersFromRepository());
        $this->assertCount(1, $this->payoutsReceiver->getSent());
        $this->assertCount(2, $this->getPayoutsFromRepository());

        // 0 eligible invoice
        $this->executeCommand(['mirakl_shop_id' => MiraklMock::INVOICE_SHOP_NOT_READY]);

        // None dispatched
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(9, $this->getTransfersFromRepository());
        $this->assertCount(0, $this->payoutsReceiver->getSent());
        $this->assertCount(3, $this->getPayoutsFromRepository());
    }

    public function testFirstExecution()
    {
        $this->configService->setSellerSettlementCheckpoint(null);
        $this->executeCommand();
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(0, $this->getTransfersFromRepository());
        $this->assertCount(0, $this->payoutsReceiver->getSent());
        $this->assertCount(0, $this->getPayoutsFromRepository());
    }

    public function testNoNewInvoices()
    {
        $this->configService->setSellerSettlementCheckpoint(MiraklMock::INVOICE_DATE_NO_NEW_INVOICES);
        $this->executeCommand();
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(0, $this->getTransfersFromRepository());
        $this->assertCount(0, $this->payoutsReceiver->getSent());
        $this->assertCount(0, $this->getPayoutsFromRepository());
    }

    public function testNewInvoices()
    {
        // 3 new invoices, one dispatchable
        $this->configService->setSellerSettlementCheckpoint(MiraklMock::INVOICE_DATE_3_INVOICES_1_VALID);
        $this->executeCommand();
        $this->assertCount(3, $this->transfersReceiver->getSent());
        $this->assertCount(9, $this->getTransfersFromRepository());
        $this->assertCount(1, $this->payoutsReceiver->getSent());
        $this->assertCount(3, $this->getPayoutsFromRepository());
    }

    public function testBacklog()
    {
        // 14 new invoices, all dispatchable
        $this->configService->setSellerSettlementCheckpoint(MiraklMock::INVOICE_DATE_14_NEW_INVOICES_ALL_READY);
        $this->executeCommand();
        $this->assertCount(42, $this->transfersReceiver->getSent());
        $this->assertCount(42, $this->getTransfersFromRepository());
        $this->assertCount(14, $this->payoutsReceiver->getSent());
        $this->assertCount(14, $this->getPayoutsFromRepository());

        // 0 new invoice
        $transfers = $this->getTransfersFromRepository();
        $payouts = $this->getPayoutsFromRepository();
        $this->executeCommand();
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(0, $this->payoutsReceiver->getSent());
        $this->assertCount(42, $transfers);
        $this->assertCount(14, $payouts);

        // Put 12 transfers and payouts back in the backlog
        for ($i = 0, $j = 12; $i < $j; $i++) {
            if (0 === $i % 2) {
                $this->mockOnHoldTransfer($transfers[$i]);
                $this->mockFailedPayout($payouts[$i]);
            } else {
                $this->mockFailedTransfer($transfers[$i]);
                $this->mockOnHoldPayout($payouts[$i]);
            }
        }

        $this->executeCommand();
        $this->assertCount(12, $this->transfersReceiver->getSent());
        $this->assertCount(42, $this->getTransfersFromRepository());
        $this->assertCount(12, $this->payoutsReceiver->getSent());
        $this->assertCount(14, $this->getPayoutsFromRepository());

        // Put 12 transfers back in the backlog while payouts are all created
        for ($i = 0, $j = 12; $i < $j; $i++) {
            if (0 === $i % 2) {
                $this->mockOnHoldTransfer($transfers[$i]);
            } else {
                $this->mockFailedTransfer($transfers[$i]);
            }
        }

        foreach ($payouts as $payout) {
            $this->mockCreatedPayout($payout);
        }

        $this->executeCommand();
        $this->assertCount(12, $this->transfersReceiver->getSent());
        $this->assertCount(42, $this->getTransfersFromRepository());
        $this->assertCount(0, $this->payoutsReceiver->getSent());
        $this->assertCount(14, $this->getPayoutsFromRepository());

        // Put 12 payouts back in the backlog while transfers are all created
        for ($i = 0, $j = 12; $i < $j; $i++) {
            if (0 === $i % 2) {
                $this->mockOnHoldPayout($payouts[$i]);
            } else {
                $this->mockFailedPayout($payouts[$i]);
            }
        }

        foreach ($transfers as $transfer) {
            $this->mockCreatedTransfer($transfer);
        }

        $this->executeCommand();
        $this->assertCount(0, $this->transfersReceiver->getSent());
        $this->assertCount(42, $this->getTransfersFromRepository());
        $this->assertCount(12, $this->payoutsReceiver->getSent());
        $this->assertCount(14, $this->getPayoutsFromRepository());
    }
}
