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

		private function getProductTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_PRODUCT_ORDER
        ]);
		}

		private function getServiceTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_SERVICE_ORDER
        ]);
		}

    // public function testFirstExecution()
    // {
		// 		$this->configService->setProductPaymentSplitCheckpoint(null);
		// 		$this->configService->setServicePaymentSplitCheckpoint(null);
    //     $this->executeCommand();
    //     $this->assertCount(0, $this->doctrineReceiver->getSent());
    //     $this->assertCount(0, $this->getProductTransfersFromRepository());
    //     $this->assertCount(0, $this->getServiceTransfersFromRepository());
    // }
		//
    // public function testNoNewProductOrders()
    // {
		// 		$this->configService->setProductPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_NO_NEW_ORDERS);
    //     $this->executeCommand();
    //     $this->assertCount(0, $this->doctrineReceiver->getSent());
    //     $this->assertCount(0, $this->getProductTransfersFromRepository());
    // }

    public function testNewProductOrders()
    {
				// 3 new orders, one dispatchable
				$this->configService->setProductPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_3_NEW_ORDERS_1_READY);
        $this->executeCommand();
        $this->assertCount(1, $this->doctrineReceiver->getSent());
        $this->assertCount(3, $this->getProductTransfersFromRepository());

				// 14 new orders, all dispatchable
				$this->configService->setProductPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
        $this->executeCommand();
        $this->assertCount(14, $this->doctrineReceiver->getSent());
        $this->assertCount(17, $this->getProductTransfersFromRepository());

				$checkpoint = $this->configService->getProductPaymentSplitCheckpoint();
        $this->assertEquals(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE, $checkpoint);
    }

    // public function testProductBacklog()
    // {
		// 		// 14 new orders, all dispatchable
		// 		$this->configService->setProductPaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
    //     $this->executeCommand();
    //     $this->assertCount(14, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $this->getProductTransfersFromRepository());
		//
		// 		// 0 new order
		// 		$transfers = $this->getProductTransfersFromRepository();
    //     $this->executeCommand();
    //     $this->assertCount(0, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $transfers);
		//
		// 		// Put 12 of them back in the backlog
		// 		for ($i = 0, $j = 12; $i < $j; $i++) {
		// 				if (0 === $i % 2) {
		// 						$this->mockOnHoldTransfer($transfers[$i]);
		// 				} else {
		// 						$this->mockFailedTransfer($transfers[$i]);
		// 				}
		// 		}
		//
    //     $this->executeCommand();
    //     $this->assertCount(12, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $this->getProductTransfersFromRepository());
    // }
		//
    // public function testNoNewServiceOrders()
    // {
		// 		$this->configService->setServicePaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_NO_NEW_ORDERS);
    //     $this->executeCommand();
    //     $this->assertCount(0, $this->doctrineReceiver->getSent());
    //     $this->assertCount(0, $this->getServiceTransfersFromRepository());
    // }
		//
    // public function testNewServiceOrders()
    // {
		// 		// 3 new orders, one dispatchable
		// 		$this->configService->setServicePaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_3_NEW_ORDERS_1_READY);
    //     $this->executeCommand();
    //     $this->assertCount(1, $this->doctrineReceiver->getSent());
    //     $this->assertCount(3, $this->getServiceTransfersFromRepository());
		//
		// 		// 14 new orders, all dispatchable
		// 		$this->configService->setServicePaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
    //     $this->executeCommand();
    //     $this->assertCount(14, $this->doctrineReceiver->getSent());
    //     $this->assertCount(17, $this->getServiceTransfersFromRepository());
		//
		// 		$checkpoint = $this->configService->getServicePaymentSplitCheckpoint();
    //     $this->assertEquals(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE, $checkpoint);
    // }
		//
    // public function testServiceBacklog()
    // {
		// 		// 14 new orders, all dispatchable
		// 		$this->configService->setServicePaymentSplitCheckpoint(MiraklMockedHttpClient::ORDER_DATE_14_NEW_ORDERS_ALL_READY);
    //     $this->executeCommand();
    //     $this->assertCount(14, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $this->getServiceTransfersFromRepository());
		//
		// 		// 0 new order
		// 		$transfers = $this->getServiceTransfersFromRepository();
    //     $this->executeCommand();
    //     $this->assertCount(0, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $transfers);
		//
		// 		// Put 12 of them back in the backlog
		// 		for ($i = 0, $j = 12; $i < $j; $i++) {
		// 				if (0 === $i % 2) {
		// 						$this->mockOnHoldTransfer($transfers[$i]);
		// 				} else {
		// 						$this->mockFailedTransfer($transfers[$i]);
		// 				}
		// 		}
		//
    //     $this->executeCommand();
    //     $this->assertCount(12, $this->doctrineReceiver->getSent());
    //     $this->assertCount(14, $this->getServiceTransfersFromRepository());
    // }
}
