<?php

namespace App\Tests\Command;

use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class PaymentRefundCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;
    protected $commandTester;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    private $refundReceiver;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    private $transferReceiver;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-refund');
				$this->commandTester = new CommandTester($this->command);

				$this->refundReceiver = self::$container->get('messenger.transport.process_refunds');
        $this->transferReceiver = self::$container->get('messenger.transport.process_transfers');

        $this->miraklClient = self::$container->get('App\Service\MiraklClient');

        $this->paymentMappingRepository = self::$container->get('doctrine')->getRepository(PaymentMapping::class);
        $this->stripeRefundRepository = self::$container->get('doctrine')->getRepository(StripeRefund::class);
        $this->stripeTransferRepository = self::$container->get('doctrine')->getRepository(StripeTransfer::class);
    }

		private function executeCommand() {
				$this->refundReceiver->reset();
				$this->transferReceiver->reset();
        $this->commandTester->execute([ 'command' => $this->command->getName() ]);
        $this->assertEquals(0, $this->commandTester->getStatusCode());
		}

		private function getBasicRefundFromRepository() {
				return $this->stripeRefundRepository->findOneBy([
						'miraklRefundId' => MiraklMock::ORDER_REFUND_BASIC
				]);
		}

		private function getRefundsFromRepository() {
				return $this->stripeRefundRepository->findAll();
		}

		private function getTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND
        ]);
		}

    private function mockOrderTransfer(array $order, string $transactionId)
    {
        $transfer = new StripeTransfer();
				$transfer->setType(StripeTransfer::TRANSFER_ORDER);
				$transfer->setMiraklId($order['order_id']);
        $transfer->setAmount(gmp_intval((string) ($order['amount'] * 100)));
        $transfer->setCurrency(strtolower($order['currency_iso_code']));
				$transfer->setTransactionId($transactionId);
				$transfer->setStatus(StripeTransfer::TRANSFER_CREATED);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

		private function mockRefundCreated(StripeRefund $refund) {
				$refund->setStripeRefundId(StripeMock::REFUND_BASIC);
				$refund->setMiraklValidationTime(new \Datetime());
				$refund->setStatus(StripeRefund::REFUND_CREATED);
				$refund->setStatusReason(null);

				return $refund;
		}

    public function testExecute()
    {
        // Nothing is ready
        $this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getRefundsFromRepository());
        $this->assertCount(14, $this->getTransfersFromRepository());

				// Mock ORDER_REFUND_BASIC now has a payment ID
				$orders = $this->miraklClient->listPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklMock::ORDER_REFUND_BASIC);
				$this->mockOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

				// Refund for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(1, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $refunds = $this->getRefundsFromRepository());
        $this->assertCount(14, $this->getTransfersFromRepository());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refunds[0]->getStatus());

				// Mock ORDER_REFUND_BASIC has been created
				$refunds = $this->getRefundsFromRepository();
				$this->mockRefundCreated($this->getBasicRefundFromRepository());

				// Transfer for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(1, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getRefundsFromRepository());
        $this->assertCount(14, $transfers = $this->getTransfersFromRepository());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfers[0]->getStatus());

				// Nothing is ready again
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getRefundsFromRepository());
        $this->assertCount(14, $this->getTransfersFromRepository());
    }
}
