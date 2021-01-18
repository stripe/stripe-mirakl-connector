<?php

namespace App\Tests\Factory;

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

    private function mockServiceOrderTransfer(array $order, ?string $transactionId)
    {
				$transfer = $this->mockProductOrderTransfer($order, $transactionId);
				$transfer->setType(StripeTransfer::TRANSFER_SERVICE_ORDER);

				$this->stripeTransferRepository->flush();

				return $transfer;
    }

    private function mockProductOrderTransfer(array $order, ?string $transactionId)
    {
        $transfer = new StripeTransfer();
				$transfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
				$transfer->setMiraklId($order['order_id']);
        $transfer->setAmount(gmp_intval((string) ($order['amount'] * 100)));
        $transfer->setCurrency(strtolower($order['currency_iso_code'] ?? $order['currency_code']));
				$transfer->setTransactionId($transactionId);
				$transfer->setStatus(StripeTransfer::TRANSFER_PENDING);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

    private function mockPaymentMapping(string $orderId, string $chargeId)
    {
        $paymentMapping = new PaymentMapping();
				$paymentMapping->setMiraklOrderId($orderId);
				$paymentMapping->setStripeChargeId($chargeId);

				$this->paymentMappingRepository->persistAndFlush($paymentMapping);

				return $paymentMapping;
    }

    private function parseProductOrderRefund(array $order, int $orderLineOffset, int $orderRefundOffset)
    {
				$orderLine = $order['order_lines']['order_line'][$orderLineOffset];
				$orderRefund = $orderLine['refunds']['refund'][$orderRefundOffset];

				$orderRefund['currency_code'] = $order['currency_iso_code'];
				$orderRefund['order_id'] = $order['order_id'];
				$orderRefund['order_line_id'] = $orderLine['order_line_id'];

				return $orderRefund;
    }

    public function testCreateFromProductOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, MiraklMock::PRODUCT_ORDER_REFUND_BASIC);
				$this->mockProductOrderTransfer($pendingRefunds[$orderId], StripeMock::CHARGE_BASIC);

				$orderRefund = $this->parseProductOrderRefund($pendingRefunds[$orderId], 0, 0);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund, MiraklClient::ORDER_TYPE_PRODUCT);

        $this->assertEquals(StripeRefund::REFUND_PRODUCT_ORDER, $refund->getType());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
        $this->assertNull($refund->getStatusReason());
        $this->assertEquals($orderId, $refund->getMiraklOrderId());
        $this->assertEquals(MiraklMock::PRODUCT_ORDER_REFUND_BASIC, $refund->getMiraklRefundId());
        $this->assertEquals($orderId . '-1', $refund->getMiraklOrderLineId());
        $this->assertEquals(1234, $refund->getAmount());
        $this->assertEquals('eur', $refund->getCurrency());
        $this->assertNull($refund->getStripeRefundId());
        $this->assertNull($refund->getMiraklValidationTime());
    }

    public function testUpdateProductRefund()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, MiraklMock::PRODUCT_ORDER_REFUND_BASIC);
				$this->mockProductOrderTransfer($pendingRefunds[$orderId], null);

				$orderRefund = $this->parseProductOrderRefund($pendingRefunds[$orderId], 0, 0);
				$refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund, MiraklClient::ORDER_TYPE_PRODUCT);

        $this->assertEquals(StripeRefund::REFUND_ON_HOLD, $refund->getStatus());

				$this->mockPaymentMapping($orderId, StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->updateRefund($refund);

        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
    }

    public function testCreateFromServiceOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listServicePendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, MiraklMock::SERVICE_ORDER_REFUND_BASIC);
				$this->mockServiceOrderTransfer($pendingRefunds[$orderId], StripeMock::CHARGE_BASIC);

				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefunds[$orderId], MiraklClient::ORDER_TYPE_SERVICE);

        $this->assertEquals(StripeRefund::REFUND_SERVICE_ORDER, $refund->getType());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
        $this->assertNull($refund->getStatusReason());
        $this->assertEquals($orderId, $refund->getMiraklOrderId());
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

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, MiraklMock::SERVICE_ORDER_REFUND_BASIC);
				$this->mockServiceOrderTransfer($pendingRefunds[$orderId], null);

				$refund = $this->stripeRefundFactory->createFromOrderRefund($pendingRefunds[$orderId], MiraklClient::ORDER_TYPE_SERVICE);

        $this->assertEquals(StripeRefund::REFUND_ON_HOLD, $refund->getStatus());

				$this->mockPaymentMapping($orderId, StripeMock::CHARGE_BASIC);
				$refund = $this->stripeRefundFactory->updateRefund($refund);

        $this->assertEquals(StripeRefund::REFUND_PENDING, $refund->getStatus());
    }

    public function testChargeStatuses()
    {
				$chargeStatuses = [
						StripeRefund::REFUND_ON_HOLD => [
								'CHARGE_STATUS_PENDING', 'CHARGE_STATUS_AUTHORIZED'
						],
						StripeRefund::REFUND_PENDING => [
								'CHARGE_BASIC', 'CHARGE_PAYMENT', 'CHARGE_STATUS_CAPTURED'
						],
						StripeRefund::REFUND_ABORTED => [
								'CHARGE_STATUS_FAILED', 'CHARGE_REFUNDED', 'CHARGE_NOT_FOUND'
						]
				];

				$types = [
						MiraklClient::ORDER_TYPE_PRODUCT => MiraklMock::PRODUCT_ORDER_REFUND_BASIC,
						MiraklClient::ORDER_TYPE_SERVICE => MiraklMock::SERVICE_ORDER_REFUND_BASIC
				];
				foreach ($types as $type => $refundId) {
						$orderId = MiraklMock::getOrderIdFromRefundId($type, $refundId);
						$pendingRefunds = $this->miraklClient->{"list{$type}PendingRefunds"}();
						$pendingRefund = $pendingRefunds[$orderId];

						$i = 0;
						foreach ($chargeStatuses as $expectedRefundStatus => $consts) {
								foreach ($consts as $const) {
										$i++;

										$order = $pendingRefund;
										$order['order_id'] .= "_{$type}_$i";

										$chargeId = constant("App\Tests\StripeMockedHttpClient::$const");
										$this->{"mock{$type}OrderTransfer"}($order, $chargeId);

										if (MiraklClient::ORDER_TYPE_PRODUCT === $type) {
												$orderRefund = $this->parseProductOrderRefund($order, 0, 0);
										} else {
												$orderRefund = $order;
										}

										$refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund, $type);

						        $this->assertEquals(
												$expectedRefundStatus,
												$refund->getStatus(),
												"Expected $expectedRefundStatus for $const ($type): " . $refund->getStatusReason()
										);
								}
						}
				}
    }

    public function testPaymentIntentStatuses()
    {
				$chargeStatuses = [
						StripeRefund::REFUND_ON_HOLD => [
								'PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD',
								'PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION',
								'PAYMENT_INTENT_STATUS_REQUIRES_ACTION',
								'PAYMENT_INTENT_STATUS_PROCESSING',
								'PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE'
						],
						StripeRefund::REFUND_PENDING => [
								'PAYMENT_INTENT_BASIC', 'PAYMENT_INTENT_STATUS_SUCCEEDED'
						],
						StripeRefund::REFUND_ABORTED => [
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
						$orderId = MiraklMock::getOrderIdFromRefundId($type, $refundId);
						$pendingRefunds = $this->miraklClient->{"list{$type}PendingRefunds"}();
						$pendingRefund = $pendingRefunds[$orderId];

						$i = 0;
						foreach ($chargeStatuses as $expectedRefundStatus => $consts) {
								foreach ($consts as $const) {
										$i++;

										$order = $pendingRefund;
										$order['order_id'] .= "_{$type}_$i";

										$chargeId = constant("App\Tests\StripeMockedHttpClient::$const");
										$this->{"mock{$type}OrderTransfer"}($order, $chargeId);

										if (MiraklClient::ORDER_TYPE_PRODUCT === $type) {
												$orderRefund = $this->parseProductOrderRefund($order, 0, 0);
										} else {
												$orderRefund = $order;
										}

										$refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund, MiraklClient::ORDER_TYPE_PRODUCT);

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
