<?php

namespace App\Tests\Command;

use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
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

		private function getBasicProductRefundFromRepository() {
				return $this->stripeRefundRepository->findOneBy([
						'miraklRefundId' => MiraklMock::PRODUCT_ORDER_REFUND_BASIC
				]);
		}

		private function getBasicServiceRefundFromRepository() {
				return $this->stripeRefundRepository->findOneBy([
						'miraklRefundId' => MiraklMock::SERVICE_ORDER_REFUND_BASIC
				]);
		}

		private function getProductRefundsFromRepository() {
				return $this->stripeRefundRepository->findBy([
            'type' => StripeRefund::REFUND_PRODUCT_ORDER
        ]);
		}

		private function getServiceRefundsFromRepository() {
				return $this->stripeRefundRepository->findBy([
            'type' => StripeRefund::REFUND_SERVICE_ORDER
        ]);
		}

		private function getProductTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
						'miraklId' => range(
								MiraklMock::PRODUCT_ORDER_REFUND_BASIC,
								MiraklMock::PRODUCT_ORDER_REFUND_BASIC + 14
						)
        ]);
		}

		private function getServiceTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
						'miraklId' => range(
								MiraklMock::SERVICE_ORDER_REFUND_BASIC,
								MiraklMock::SERVICE_ORDER_REFUND_BASIC + 14
						)
        ]);
		}

    private function mockProductOrderTransfer(array $order, string $transactionId)
    {
        $transfer = new StripeTransfer();
				$transfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
				$transfer->setMiraklId($order['order_id']);
        $transfer->setAmount(gmp_intval((string) ($order['amount'] * 100)));
        $transfer->setCurrency(strtolower($order['currency_iso_code'] ?? $order['currency_code']));
				$transfer->setTransactionId($transactionId);
				$transfer->setStatus(StripeTransfer::TRANSFER_CREATED);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

    private function mockServiceOrderTransfer(array $order, string $transactionId)
    {
				$transfer = $this->mockProductOrderTransfer($order, $transactionId);
				$transfer->setType(StripeTransfer::TRANSFER_SERVICE_ORDER);

				$this->stripeTransferRepository->flush();

				return $transfer;
		}

		private function mockRefundCreated(StripeRefund $refund) {
				$refund->setStripeRefundId(StripeMock::REFUND_BASIC);
				$refund->setMiraklValidationTime(new \Datetime());
				$refund->setStatus(StripeRefund::REFUND_CREATED);
				$refund->setStatusReason(null);

				return $refund;
		}

    public function testProductRefunds()
    {
        // Nothing is ready
        $this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getProductRefundsFromRepository());
        $this->assertCount(14, $this->getProductTransfersFromRepository());

				// Mock ORDER_REFUND_BASIC now has a payment ID
				$orders = $this->miraklClient->listProductPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, MiraklMock::PRODUCT_ORDER_REFUND_BASIC);
				$this->mockProductOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

				// Refund for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(1, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $refunds = $this->getProductRefundsFromRepository());
        $this->assertCount(14, $this->getProductTransfersFromRepository());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refunds[0]->getStatus());

				// Mock ORDER_REFUND_BASIC has been created
				$refunds = $this->getProductRefundsFromRepository();
				$this->mockRefundCreated($this->getBasicProductRefundFromRepository());

				// Transfer for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(1, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getProductRefundsFromRepository());
        $this->assertCount(14, $transfers = $this->getProductTransfersFromRepository());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfers[0]->getStatus());

				// Nothing is ready again
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getProductRefundsFromRepository());
        $this->assertCount(14, $this->getProductTransfersFromRepository());
    }

    public function testServiceRefunds()
    {
        // Nothing is ready
        $this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getServiceRefundsFromRepository());
        $this->assertCount(14, $this->getServiceTransfersFromRepository());

				// Mock ORDER_REFUND_BASIC now has a payment ID
				$orders = $this->miraklClient->listServicePendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, MiraklMock::SERVICE_ORDER_REFUND_BASIC);
				$this->mockServiceOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

				// Refund for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(1, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $refunds = $this->getServiceRefundsFromRepository());
        $this->assertCount(14, $this->getServiceTransfersFromRepository());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refunds[0]->getStatus());

				// Mock ORDER_REFUND_BASIC has been created
				$refunds = $this->getServiceRefundsFromRepository();
				$this->mockRefundCreated($this->getBasicServiceRefundFromRepository());

				// Transfer for ORDER_REFUND_BASIC is ready
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(1, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getServiceRefundsFromRepository());
        $this->assertCount(14, $transfers = $this->getServiceTransfersFromRepository());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfers[0]->getStatus());

				// Nothing is ready again
				$this->executeCommand();
        $this->assertCount(0, $this->refundReceiver->getSent());
        $this->assertCount(0, $this->transferReceiver->getSent());
        $this->assertCount(14, $this->getServiceRefundsFromRepository());
        $this->assertCount(14, $this->getServiceTransfersFromRepository());
    }
}
