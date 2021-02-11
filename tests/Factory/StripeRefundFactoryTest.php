<?php

namespace App\Tests\Factory;

use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Factory\StripeRefundFactory;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StripeRefundFactoryTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeRefundFactory
     */
    private $stripeRefundFactory;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var array
     */
    private $pendingRefunds;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

				$this->paymentMappingRepository = $container->get('doctrine')
				->getRepository(PaymentMapping::class);
				$this->stripeTransferRepository = $container->get('doctrine')
								->getRepository(StripeTransfer::class);
        $this->stripeRefundFactory = new StripeRefundFactory(
            $this->paymentMappingRepository,
						$this->stripeTransferRepository,
            $container->get('App\Service\StripeClient')
        );
        $this->stripeRefundFactory->setLogger(new NullLogger());

        $this->miraklClient = $container->get('App\Service\MiraklClient');
    }

    private function mockOrderTransfer(MiraklPendingRefund $order, ?string $transactionId)
    {
				if (is_a($order, MiraklServicePendingRefund::class)) {
						$type = StripeTransfer::TRANSFER_SERVICE_ORDER;
				} else {
						$type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
				}

        $transfer = new StripeTransfer();
				$transfer->setType($type);
				$transfer->setMiraklId($order->getOrderId());
        $transfer->setAmount(gmp_intval((string) ($order->getAmount() * 100)));
        $transfer->setCurrency(strtolower($order->getCurrency()));
				$transfer->setTransactionId($transactionId);
				$transfer->setStatus(StripeTransfer::TRANSFER_PENDING);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

    private function mockPaymentMapping(string $orderId, string $chargeId)
    {
        $paymentMapping = new PaymentMapping();
				$paymentMapping->setMiraklCommercialOrderId($orderId);
				$paymentMapping->setStripeChargeId($chargeId);

				$this->paymentMappingRepository->persist($paymentMapping);
				$this->paymentMappingRepository->flush();

				return $paymentMapping;
    }

    public function testCreateFromProductOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();
				$pendingRefund = $pendingRefunds[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];

				$this->mockOrderTransfer($pendingRefund, StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefund);

        $this->assertEquals(StripeRefund::REFUND_PRODUCT_ORDER, $refund->getType());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
        $this->assertNull($refund->getStatusReason());
        $this->assertEquals($pendingRefund->getOrderId(), $refund->getMiraklOrderId());
        $this->assertEquals(MiraklMock::PRODUCT_ORDER_REFUND_BASIC, $refund->getMiraklRefundId());
        $this->assertEquals($pendingRefund->getOrderId() . '-1', $refund->getMiraklOrderLineId());
        $this->assertEquals(1234, $refund->getAmount());
        $this->assertEquals('eur', $refund->getCurrency());
        $this->assertNull($refund->getStripeRefundId());
        $this->assertNull($refund->getMiraklValidationTime());
    }

    public function testUpdateProductRefund()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();
				$pendingRefund = $pendingRefunds[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];

				$this->mockOrderTransfer($pendingRefund, null);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefund);

        $this->assertEquals(StripeRefund::REFUND_ON_HOLD, $refund->getStatus());

				$this->mockPaymentMapping($pendingRefund->getCommercialId(), StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->updateRefund($refund);

        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
    }

    public function testCreateFromServiceOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listServicePendingRefunds();
				$pendingRefund = $pendingRefunds[MiraklMock::SERVICE_ORDER_REFUND_BASIC];

				$this->mockOrderTransfer($pendingRefund, StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefund);

        $this->assertEquals(StripeRefund::REFUND_SERVICE_ORDER, $refund->getType());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
        $this->assertNull($refund->getStatusReason());
        $this->assertEquals($pendingRefund->getOrderId(), $refund->getMiraklOrderId());
        $this->assertEquals(MiraklMock::SERVICE_ORDER_REFUND_BASIC, $refund->getMiraklRefundId());
        $this->assertNull($refund->getMiraklOrderLineId());
        $this->assertEquals(1234, $refund->getAmount());
        $this->assertEquals('eur', $refund->getCurrency());
        $this->assertNull($refund->getStripeRefundId());
        $this->assertNull($refund->getMiraklValidationTime());
    }

    public function testUpdateServiceRefund()
    {
				$pendingRefunds = $this->miraklClient->listServicePendingRefunds();
				$pendingRefund = $pendingRefunds[MiraklMock::SERVICE_ORDER_REFUND_BASIC];

				$this->mockOrderTransfer($pendingRefund, null);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefund);

        $this->assertEquals(StripeRefund::REFUND_ON_HOLD, $refund->getStatus());

				$this->mockPaymentMapping($pendingRefund->getCommercialId(), StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->updateRefund($refund);

        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
    }

    public function testPaymentStatuses()
    {
				$chargeStatuses = [
						StripeRefund::REFUND_ON_HOLD => [
								'CHARGE_STATUS_PENDING', 'CHARGE_STATUS_AUTHORIZED',
								'PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD',
								'PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION',
								'PAYMENT_INTENT_STATUS_REQUIRES_ACTION',
								'PAYMENT_INTENT_STATUS_PROCESSING',
								'PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE'
						],
						StripeRefund::REFUND_PENDING => [
								'CHARGE_BASIC', 'CHARGE_PAYMENT', 'CHARGE_STATUS_CAPTURED',
								'PAYMENT_INTENT_BASIC', 'PAYMENT_INTENT_STATUS_SUCCEEDED'
						],
						StripeRefund::REFUND_ABORTED => [
								'CHARGE_STATUS_FAILED', 'CHARGE_REFUNDED', 'CHARGE_NOT_FOUND',
								'PAYMENT_INTENT_STATUS_CANCELED',
								'PAYMENT_INTENT_REFUNDED',
								'PAYMENT_INTENT_NOT_FOUND'
						]
				];

				$types = [
						MiraklClient::ORDER_TYPE_PRODUCT => MiraklMock::PRODUCT_ORDER_REFUND_BASIC,
						MiraklClient::ORDER_TYPE_SERVICE => MiraklMock::SERVICE_ORDER_REFUND_BASIC
				];
				foreach ($types as $type => $refundId) {
						$pendingRefunds = $this->miraklClient->{"list{$type}PendingRefunds"}();
						$pendingRefund = $pendingRefunds[$refundId];

						$i = 0;
						foreach ($chargeStatuses as $expectedRefundStatus => $consts) {
								foreach ($consts as $const) {
										$i++;

										$order = $pendingRefund->getOrder();
										$order['order_id'] .= "_{$type}_$i";
										$className = "App\Entity\Mirakl{$type}PendingRefund";
										$tmpPendingRefund = new $className($order);

										$chargeId = constant("App\Tests\StripeMockedHttpClient::$const");
										$this->mockOrderTransfer($tmpPendingRefund, $chargeId);

										$refund = $this->stripeRefundFactory->createFromOrderRefund($tmpPendingRefund);

						        $this->assertEquals(
												$expectedRefundStatus,
												$refund->getStatus(),
												"Expected $expectedRefundStatus for $const ($type): " . $refund->getStatusReason()
										);
								}
						}
				}
    }
}
