<?php

namespace App\Tests\Command;

use App\Entity\StripeTransfer;
use App\Repository\StripeTransferRepository;
use App\Service\ConfigService;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use App\Tests\MiraklMockedHttpClient;
use App\Tests\StripeMockedHttpClient;

class PaymentSplitCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->command = $application->find('connector:dispatch:process-transfer');
				$this->commandTester = new CommandTester($this->command);

        $this->doctrineReceiver = self::$container->get('messenger.transport.process_transfers');
        $this->configService = self::$container->get('App\Service\ConfigService');
        $this->stripeTransferRepository = self::$container->get('doctrine')->getRepository(StripeTransfer::class);
    }

		private function executeCommand($arguments = null) {
				$this->doctrineReceiver->reset();
        $this->commandTester->execute(array_merge([
            'command' => $this->command->getName()
        ], $arguments ?? []));
        $this->assertEquals(0, $this->commandTester->getStatusCode());
		}

		private function mockOnHoldTransfer(StripeTransfer $transfer) {
				$transfer->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
				$transfer->setStatusReason('reason');
				$this->stripeTransferRepository->flush();
		}

		private function mockFailedTransfer(StripeTransfer $transfer) {
				$transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
				$transfer->setStatusReason('reason');
				$this->stripeTransferRepository->flush();
		}

		private function mockAbortedTransfer(StripeTransfer $transfer) {
				$transfer->setStatus(StripeTransfer::TRANSFER_ABORTED);
				$transfer->setStatusReason('reason');
				$this->stripeTransferRepository->flush();
		}

		private function mockCreatedTransfer(StripeTransfer $transfer) {
				$transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
				$transfer->setStatusReason(null);
				$transfer->setTransferId(StripeMockedHttpClient::TRANSFER_BASIC);
				$this->stripeTransferRepository->flush();
		}

		private function getTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_ORDER
        ]);
		}

    public function testByOrderIds()
    {
				// 2 eligible orders
        $this->executeCommand([
						'mirakl_order_ids' => [
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPED
						]
        ]);

				// Both dispatched
        $this->assertCount(2, $this->doctrineReceiver->getSent());
        $this->assertCount(2, $this->getTransfersFromRepository());

				// 1 eligible order
        $this->executeCommand([
						'mirakl_order_ids' => [
								MiraklMockedHttpClient::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
								MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
						]
        ]);

				// Only RECEIVED is dispatched
        $this->assertCount(1, $this->doctrineReceiver->getSent());
        $this->assertCount(4, $this->getTransfersFromRepository());

				// 0 eligible order
				$this->mockAbortedTransfer($this->stripeTransferRepository->findOneBy([
            'miraklId' => MiraklMockedHttpClient::ORDER_STATUS_SHIPPING
        ]));
				$this->mockCreatedTransfer($this->stripeTransferRepository->findOneBy([
            'miraklId' => MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
        ]));
        $this->executeCommand([
						'mirakl_order_ids' => [
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
								MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
						]
        ]);

				// None dispatched
        $this->assertCount(0, $this->doctrineReceiver->getSent());
        $this->assertCount(4, $this->getTransfersFromRepository());
    }

    public function testNoNewOrders()
    {
				$this->configService->setPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_NO_NEW_ORDERS);
        $this->executeCommand();
        $this->assertCount(0, $this->doctrineReceiver->getSent());
        $this->assertCount(0, $this->getTransfersFromRepository());
    }

    public function testNewOrders()
    {
				// 3 new orders, one dispatchable
				$this->configService->setPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_3_NEW_ORDERS_1_READY);
        $this->executeCommand();
        $this->assertCount(1, $this->doctrineReceiver->getSent());
        $this->assertCount(3, $this->getTransfersFromRepository());

				// 14 new orders, all dispatchable
				$this->configService->setPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
        $this->executeCommand();
        $this->assertCount(14, $this->doctrineReceiver->getSent());
        $this->assertCount(17, $this->getTransfersFromRepository());

				$checkpoint = $this->configService->getPaymentSplitCheckpoint();
        $this->assertEquals(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE, $checkpoint);
    }

    public function testBacklog()
    {
				// 14 new orders, all dispatchable
				$this->configService->setPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
        $this->executeCommand();
        $this->assertCount(14, $this->doctrineReceiver->getSent());
        $this->assertCount(14, $this->getTransfersFromRepository());

				// 0 new order
				$transfers = $this->getTransfersFromRepository();
        $this->executeCommand();
        $this->assertCount(0, $this->doctrineReceiver->getSent());
        $this->assertCount(14, $transfers);

				// Put 12 of them back in the backlog
				for ($i = 0, $j = 12; $i < $j; $i++) {
						if (0 === $i % 2) {
								$this->mockOnHoldTransfer($transfers[$i]);
						} else {
								$this->mockFailedTransfer($transfers[$i]);
						}
				}

        $this->executeCommand();
        $this->assertCount(12, $this->doctrineReceiver->getSent());
        $this->assertCount(14, $this->getTransfersFromRepository());
    }
}
