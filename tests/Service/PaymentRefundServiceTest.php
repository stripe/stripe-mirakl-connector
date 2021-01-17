<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Factory\StripeRefundFactory;
use App\Factory\StripeTransferFactory;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\PaymentRefundService;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PaymentRefundServiceTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentRefundService
     */
    private $paymentRefundService;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

        $this->miraklClient = $container->get('App\Service\MiraklClient');
				$stripeClient = $container->get('App\Service\StripeClient');

        $this->paymentMappingRepository = $container->get('doctrine')->getRepository(PaymentMapping::class);
        $this->stripeRefundRepository = $container->get('doctrine')->getRepository(StripeRefund::class);
        $this->stripeTransferRepository = $container->get('doctrine')->getRepository(StripeTransfer::class);

				$stripeRefundFactory = new StripeRefundFactory(
            $this->paymentMappingRepository,
						$this->stripeTransferRepository,
            $stripeClient
				);
        $stripeRefundFactory->setLogger(new NullLogger());

				$stripeTransferFactory = new StripeTransferFactory(
						$container->get('doctrine')->getRepository(AccountMapping::class),
						$this->stripeRefundRepository,
						$this->stripeTransferRepository,
						$this->miraklClient,
						$stripeClient
				);
        $stripeTransferFactory->setLogger(new NullLogger());

        $this->paymentRefundService = new PaymentRefundService(
						$stripeRefundFactory,
						$stripeTransferFactory,
						$this->stripeRefundRepository,
						$this->stripeTransferRepository
				);
    }

    private function mockOrderTransfer(array $order, string $transactionId)
    {
        $transfer = new StripeTransfer();
				$transfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
				$transfer->setMiraklId($order['order_id']);
        $transfer->setAmount(gmp_intval((string) ($order['amount'] * 100)));
        $transfer->setCurrency(strtolower($order['currency_iso_code']));
				$transfer->setTransactionId($transactionId);
				$transfer->setStatus(StripeTransfer::TRANSFER_CREATED);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

		private function getBasicRefundFromRepository() {
				return $this->stripeRefundRepository->findOneBy([
						'miraklRefundId' => MiraklMock::ORDER_REFUND_BASIC
				]);
		}

		private function mockRefundCreated(StripeRefund $refund) {
				$refund->setStripeRefundId(StripeMock::REFUND_BASIC);
				$refund->setMiraklValidationTime(new \Datetime());
				$refund->setStatus(StripeRefund::REFUND_CREATED);
				$refund->setStatusReason(null);

				return $refund;
		}

		private function getRefundsFromRepository() {
				return $this->stripeRefundRepository->findAll();
		}

		private function getTransfersFromRepository() {
				return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND
        ]);
		}

    public function testGetRetriableTransfers()
    {
				$orders = $this->miraklClient->listProductPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklMock::ORDER_REFUND_BASIC);
				$this->mockOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
				$this->mockRefundCreated($this->getBasicRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(14, $transfers);
        $this->assertCount(14, $this->getTransfersFromRepository());

				// All except ORDER_REFUND_BASIC are retriable
        $this->assertCount(13, $this->paymentRefundService->getRetriableTransfers());
        $this->assertCount(14, $this->getTransfersFromRepository());
    }

    public function testGetTransfersFromOrders()
    {
				$orders = $this->miraklClient->listProductPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklMock::ORDER_REFUND_BASIC);
				$this->mockOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
				$this->mockRefundCreated($this->getBasicRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(14, $transfers);
        $this->assertCount(14, $this->getTransfersFromRepository());

				// All except ORDER_REFUND_BASIC are retriable
        $transfers = $this->paymentRefundService->getRetriableTransfers();
        $this->assertCount(13, $transfers);
        $this->assertCount(14, $this->getTransfersFromRepository());
    }

    public function testGetRefundsFromOrders()
    {
				$orders = $this->miraklClient->listProductPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklMock::ORDER_REFUND_BASIC);
				$this->mockOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
				$this->mockRefundCreated($this->getBasicRefundFromRepository());
        $this->assertCount(14, $refunds);

				// All except ORDER_REFUND_BASIC are retriable
        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(13, $refunds);
    }

    public function testUpdateTransfers()
    {
				$orders = $this->miraklClient->listProductPendingRefunds();
				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklMock::ORDER_REFUND_BASIC);
				$this->mockOrderTransfer($orders[$orderId],	StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(14, $refunds);

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(14, $transfers);
        $this->assertCount(14, $this->getTransfersFromRepository());

				// ORDER_REFUND_BASIC is on hold
        $transfers = $this->paymentRefundService->getRetriableTransfers();
				$transfer = $transfers[MiraklMock::ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

				// ORDER_REFUND_BASIC is pending
				$this->mockRefundCreated($this->getBasicRefundFromRepository());
        $transfers = $this->paymentRefundService->updateTransfers($transfers);
				$transfer = $transfers[MiraklMock::ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }
}
