<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Factory\StripeTransferFactory;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StripeTransferFactoryTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeTransferFactory
     */
    private $stripeTransferFactory;

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

				$this->stripeRefundRepository = $container->get('doctrine')
									->getRepository(StripeRefund::class);
				$this->stripeTransferRepository = $container->get('doctrine')
									->getRepository(StripeTransfer::class);

        $this->stripeTransferFactory = new StripeTransferFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class),
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
						$this->miraklClient,
            $container->get('App\Service\StripeClient')
        );
        $this->stripeTransferFactory->setLogger(new NullLogger());
    }

    private function mockOrderTransfer(string $orderType, array $order)
    {
				if (MiraklClient::ORDER_TYPE_SERVICE === $orderType) {
						$type = StripeTransfer::TRANSFER_SERVICE_ORDER;
						$currency = $order['currency_code'];
				} elseif (MiraklClient::ORDER_TYPE_PRODUCT === $orderType) {
						$type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
						$currency = $order['currency_iso_code'];
				}

        $transfer = new StripeTransfer();
				$transfer->setType($type);
				$transfer->setMiraklId($order['order_id']);
        $transfer->setAmount(gmp_intval((string) ($order['amount'] * 100)));
        $transfer->setCurrency(strtolower($currency));
				$transfer->setStatus(StripeTransfer::TRANSFER_PENDING);

				$this->stripeTransferRepository->persistAndFlush($transfer);

				return $transfer;
    }

    private function mockOrderTransferCreated(StripeTransfer $transfer, string $transferId)
    {
				$transfer->setTransferId($transferId);
				$transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
				$transfer->setStatusReason(null);

				$this->stripeTransferRepository->flush();

				return $transfer;
    }

    private function mockStripeRefund(string $orderType, array $order)
    {
				if (MiraklClient::ORDER_TYPE_SERVICE === $orderType) {
						$orderRefund = $order;

						$type = StripeRefund::REFUND_SERVICE_ORDER;
						$orderLineId = null;
						$currency = $order['currency_code'];
				} elseif (MiraklClient::ORDER_TYPE_PRODUCT === $orderType) {
						$orderLine = $order['order_lines']['order_line'][0];
						$orderRefund = $orderLine['refunds']['refund'][0];

						$type = StripeRefund::REFUND_PRODUCT_ORDER;
						$orderLineId = $orderLine['order_line_id'];
						$currency = $order['currency_iso_code'];
				}

        $refund = new StripeRefund();
				$refund->setType($type);
				$refund->setMiraklOrderId($order['order_id']);
				$refund->setMiraklOrderLineId($orderLineId);
				$refund->setAmount(gmp_intval((string) ($orderRefund['amount'] * 100)));
				$refund->setCurrency(strtolower($currency));
				$refund->setTransactionId(StripeMock::CHARGE_BASIC);
				$refund->setMiraklRefundId($orderRefund['id']);
				$refund->setStatus(StripeRefund::REFUND_PENDING);

				$this->stripeRefundRepository->persistAndFlush($refund);

				return $refund;
    }

    private function mockRefundCreated(StripeRefund $refund)
    {
				$refund->setStripeRefundId(StripeMock::REFUND_BASIC);
				$refund->setMiraklValidationTime(new \Datetime());
				$refund->setStatus(StripeRefund::REFUND_CREATED);
				$refund->setStatusReason(null);

				$this->stripeRefundRepository->flush();

				return $refund;
    }

    public function testCreateFromProductOrder()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listProductOrdersById([
								MiraklMock::ORDER_BASIC
						])),
						MiraklClient::ORDER_TYPE_PRODUCT
				);

        $this->assertEquals(StripeTransfer::TRANSFER_PRODUCT_ORDER, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::ORDER_BASIC, $transfer->getMiraklId());
        $this->assertNotNull($transfer->getAccountMapping());
        $this->assertNull($transfer->getTransferId());
        $this->assertNull($transfer->getTransactionId());
        $this->assertEquals(8073, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
    }

    public function testUpdateFromProductOrder()
    {
        $order = current($this->miraklClient->listProductOrdersById([
						MiraklMock::ORDER_STATUS_STAGING
				]));
				$orderId = $order['order_id'];

        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

				$order = current($this->miraklClient->listProductOrdersById([
						MiraklMock::ORDER_STATUS_SHIPPING
				]));
				$order['order_id'] = $orderId;
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testProductOrderWithTransfer()
    {
        $order = current($this->miraklClient->listProductOrdersById([
						MiraklMock::ORDER_BASIC
				]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());

				$transfer->setTransferId(StripeMock::TRANSFER_BASIC);
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
    }

    public function testProductOrderStatuses()
    {
				$orderStatuses = [
						StripeTransfer::TRANSFER_ON_HOLD => [
								'STAGING', 'WAITING_ACCEPTANCE', 'WAITING_DEBIT', 'WAITING_DEBIT_PAYMENT',
								'PARTIALLY_ACCEPTED'
						],
						StripeTransfer::TRANSFER_PENDING => [
								'SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED',
								'PARTIALLY_REFUSED'
						],
						StripeTransfer::TRANSFER_ABORTED => [
								'REFUSED', 'CANCELED'
						]
				];

				foreach ($orderStatuses as $expectedTransferStatus => $consts) {
						foreach ($consts as $const) {
				        $transfer = $this->stripeTransferFactory->createFromOrder(
										current($this->miraklClient->listProductOrdersById([
												constant("App\Tests\MiraklMockedHttpClient::ORDER_STATUS_$const")
										])),
										MiraklClient::ORDER_TYPE_PRODUCT
								);
				        $this->assertEquals(
										$expectedTransferStatus,
										$transfer->getStatus(),
										'Expected ' . $expectedTransferStatus . ' for ' . $const
								);
						}
				}
    }

    public function testProductOrderInvalidShop()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listProductOrdersById([
								MiraklMock::ORDER_INVALID_SHOP
						])),
						MiraklClient::ORDER_TYPE_PRODUCT
				);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testProductOrderInvalidAmount()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listProductOrdersById([
								MiraklMock::ORDER_INVALID_AMOUNT
						])),
						MiraklClient::ORDER_TYPE_PRODUCT
				);
        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testProductOrderDifferentAmounts()
    {
				$amounts = [
						'NO_COMMISSION' => 8472,
						'NO_TAX' => 6513,
						'PARTIAL_TAX' => 7293,
						'NO_SALES_TAX' => 7205,
						'NO_SHIPPING_TAX' => 7381
				];

				foreach ($amounts as $const => $expectedAmount) {
						$transfer = $this->stripeTransferFactory->createFromOrder(
								current($this->miraklClient->listProductOrdersById([
										constant("App\Tests\MiraklMockedHttpClient::ORDER_AMOUNT_$const")
								])),
								MiraklClient::ORDER_TYPE_PRODUCT
						);
						$this->assertEquals(
								$expectedAmount,
								$transfer->getAmount(),
								'Expected ' . $expectedAmount . ' for ' . $const
						);
				}
    }

    public function testProductOrderWithCharge()
    {
				$chargeStatuses = [
						StripeTransfer::TRANSFER_ON_HOLD => [
								'CHARGE_STATUS_PENDING', 'CHARGE_STATUS_AUTHORIZED'
						],
						StripeTransfer::TRANSFER_PENDING => [
								'CHARGE_BASIC', 'CHARGE_PAYMENT', 'CHARGE_STATUS_CAPTURED'
						],
						StripeTransfer::TRANSFER_ABORTED => [
								'CHARGE_STATUS_FAILED', 'CHARGE_REFUNDED', 'CHARGE_NOT_FOUND'
						]
				];

				$order = current($this->miraklClient->listProductOrdersById([
						MiraklMock::ORDER_BASIC
				]));
				foreach ($chargeStatuses as $expectedTransferStatus => $consts) {
						foreach ($consts as $const) {
								$order['transaction_number'] = constant("App\Tests\StripeMockedHttpClient::$const");
				        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_PRODUCT);
				        $this->assertEquals(
										$expectedTransferStatus,
										$transfer->getStatus(),
										'Expected ' . $expectedTransferStatus . ' for ' . $const
								);
						}
				}
    }

    public function testProductOrderWithPaymentIntent()
    {
				$chargeStatuses = [
						StripeTransfer::TRANSFER_ON_HOLD => [
								'PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD',
								'PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION',
								'PAYMENT_INTENT_STATUS_REQUIRES_ACTION',
								'PAYMENT_INTENT_STATUS_PROCESSING',
								'PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE'
						],
						StripeTransfer::TRANSFER_PENDING => [
								'PAYMENT_INTENT_BASIC', 'PAYMENT_INTENT_STATUS_SUCCEEDED'
						],
						StripeTransfer::TRANSFER_ABORTED => [
								'PAYMENT_INTENT_STATUS_CANCELED',
								'PAYMENT_INTENT_REFUNDED',
								'PAYMENT_INTENT_NOT_FOUND'
						]
				];

				$order = current($this->miraklClient->listProductOrdersById([
						MiraklMock::ORDER_BASIC
				]));
				foreach ($chargeStatuses as $expectedTransferStatus => $consts) {
						foreach ($consts as $const) {
								$order['transaction_number'] = constant("App\Tests\StripeMockedHttpClient::$const");
				        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_PRODUCT);
				        $this->assertEquals(
										$expectedTransferStatus,
										$transfer->getStatus(),
										'Expected ' . $expectedTransferStatus . ' for ' . $const
								);
						}
				}
    }


    public function testCreateFromServiceOrder()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listServiceOrdersById([
								MiraklMock::ORDER_BASIC
						])),
						MiraklClient::ORDER_TYPE_SERVICE
				);

        $this->assertEquals(StripeTransfer::TRANSFER_SERVICE_ORDER, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::ORDER_BASIC, $transfer->getMiraklId());
        $this->assertNotNull($transfer->getAccountMapping());
        $this->assertNull($transfer->getTransferId());
        $this->assertNull($transfer->getTransactionId());
        $this->assertEquals(1415, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
    }

    public function testUpdateFromServiceOrder()
    {
        $order = current($this->miraklClient->listServiceOrdersById([
						MiraklMock::ORDER_STATUS_WAITING_SCORING
				]));
				$orderId = $order['id'];

        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_SERVICE);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

				$order = current($this->miraklClient->listServiceOrdersById([
						MiraklMock::ORDER_STATUS_ORDER_ACCEPTED
				]));
				$order['id'] = $orderId;
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testServiceOrderWithTransfer()
    {
        $order = current($this->miraklClient->listServiceOrdersById([
						MiraklMock::ORDER_BASIC
				]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order, MiraklClient::ORDER_TYPE_SERVICE);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());

				$transfer->setTransferId(StripeMock::TRANSFER_BASIC);
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
    }

    public function testServiceOrderStatuses()
    {
				$orderStatuses = [
						StripeTransfer::TRANSFER_ON_HOLD => [
								'WAITING_SCORING', 'WAITING_ACCEPTANCE', 'WAITING_DEBIT', 'WAITING_DEBIT_PAYMENT'
						],
						StripeTransfer::TRANSFER_PENDING => [
								'ORDER_ACCEPTED', 'ORDER_PENDING', 'ORDER_CLOSED'
						],
						StripeTransfer::TRANSFER_ABORTED => [
								'ORDER_REFUSED', 'ORDER_CANCELLED', 'ORDER_EXPIRED'
						]
				];

				foreach ($orderStatuses as $expectedTransferStatus => $consts) {
						foreach ($consts as $const) {
				        $transfer = $this->stripeTransferFactory->createFromOrder(
										current($this->miraklClient->listServiceOrdersById([
												constant("App\Tests\MiraklMockedHttpClient::ORDER_STATUS_$const")
										])),
										MiraklClient::ORDER_TYPE_SERVICE
								);
				        $this->assertEquals(
										$expectedTransferStatus,
										$transfer->getStatus(),
										'Expected ' . $expectedTransferStatus . ' for ' . $const
								);
						}
				}
    }

    public function testServiceOrderInvalidShop()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listServiceOrdersById([
								MiraklMock::ORDER_INVALID_SHOP
						])),
						MiraklClient::ORDER_TYPE_SERVICE
				);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testServiceOrderInvalidAmount()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
						current($this->miraklClient->listServiceOrdersById([
								MiraklMock::ORDER_INVALID_AMOUNT
						])),
						MiraklClient::ORDER_TYPE_SERVICE
				);
        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testServiceOrderDifferentAmounts()
    {
				$amounts = [
						'NO_COMMISSION' => 1814,
						'NO_TAX' => 1081,
						'PARTIAL_TAX' => 1237,
				];

				foreach ($amounts as $const => $expectedAmount) {
						$transfer = $this->stripeTransferFactory->createFromOrder(
								current($this->miraklClient->listServiceOrdersById([
										constant("App\Tests\MiraklMockedHttpClient::ORDER_AMOUNT_$const")
								])),
								MiraklClient::ORDER_TYPE_SERVICE
						);
						$this->assertEquals(
								$expectedAmount,
								$transfer->getAmount(),
								'Expected ' . $expectedAmount . ' for ' . $const
						);
				}
    }

    public function testCreateFromProductOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, MiraklMock::PRODUCT_ORDER_REFUND_BASIC);
				$order = $pendingRefunds[$orderId];
				$orderRefund = $order['order_lines']['order_line'][0]['refunds']['refund'][0];

				$orderTransfer = $this->mockOrderTransferCreated(
						$this->mockOrderTransfer(MiraklClient::ORDER_TYPE_PRODUCT, $order),
						MiraklMock::TRANSFER_BASIC
				);
				$this->mockRefundCreated($this->mockStripeRefund(MiraklClient::ORDER_TYPE_PRODUCT, $order));
				$transfer = $this->stripeTransferFactory->createFromOrderRefund($orderRefund);

        $this->assertEquals(StripeTransfer::TRANSFER_REFUND, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::PRODUCT_ORDER_REFUND_BASIC, $transfer->getMiraklId());
        $this->assertNull($transfer->getAccountMapping());;
        $this->assertNull($transfer->getTransferId());
        $this->assertEquals(MiraklMock::TRANSFER_BASIC, $transfer->getTransactionId());
        $this->assertEquals(1111, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNull($transfer->getMiraklCreatedDate());
    }

    public function testUpdateProductOrderRefundTransfer()
    {
				$pendingRefunds = $this->miraklClient->listProductPendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, MiraklMock::PRODUCT_ORDER_REFUND_BASIC);
				$order = $pendingRefunds[$orderId];
				$orderRefund = $order['order_lines']['order_line'][0]['refunds']['refund'][0];

				$orderTransfer = $this->mockOrderTransfer(MiraklClient::ORDER_TYPE_PRODUCT, $order);
				$this->mockRefundCreated($this->mockStripeRefund(MiraklClient::ORDER_TYPE_PRODUCT, $order));
				$transfer = $this->stripeTransferFactory->createFromOrderRefund($orderRefund);

        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

				// Order transfer has been processed
				$this->mockOrderTransferCreated($orderTransfer, StripeMock::TRANSFER_BASIC);

				$transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testCreateFromServiceOrderRefund()
    {
				$pendingRefunds = $this->miraklClient->listServicePendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, MiraklMock::SERVICE_ORDER_REFUND_BASIC);
				$order = $pendingRefunds[$orderId];

				$orderTransfer = $this->mockOrderTransferCreated(
						$this->mockOrderTransfer(MiraklClient::ORDER_TYPE_SERVICE, $order),
						MiraklMock::TRANSFER_BASIC
				);
				$this->mockRefundCreated($this->mockStripeRefund(MiraklClient::ORDER_TYPE_SERVICE, $order));
				$transfer = $this->stripeTransferFactory->createFromOrderRefund($order);

        $this->assertEquals(StripeTransfer::TRANSFER_REFUND, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::SERVICE_ORDER_REFUND_BASIC, $transfer->getMiraklId());
        $this->assertNull($transfer->getAccountMapping());;
        $this->assertNull($transfer->getTransferId());
        $this->assertEquals(MiraklMock::TRANSFER_BASIC, $transfer->getTransactionId());
        $this->assertEquals(1111, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNull($transfer->getMiraklCreatedDate());
    }

    public function testUpdateServiceOrderRefundTransfer()
    {
				$pendingRefunds = $this->miraklClient->listServicePendingRefunds();

				$orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, MiraklMock::SERVICE_ORDER_REFUND_BASIC);
				$order = $pendingRefunds[$orderId];

				$orderTransfer = $this->mockOrderTransfer(MiraklClient::ORDER_TYPE_SERVICE, $order);
				$this->mockRefundCreated($this->mockStripeRefund(MiraklClient::ORDER_TYPE_SERVICE, $order));
				$transfer = $this->stripeTransferFactory->createFromOrderRefund($order);

        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

				// Order transfer has been processed
				$this->mockOrderTransferCreated($orderTransfer, StripeMock::TRANSFER_BASIC);

				$transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testCreateFromInvoice()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_VALID
				));
				foreach (StripeTransfer::getInvoiceTypes() as $type) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);

		        $this->assertEquals($type, $transfer->getType());
		        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
		        $this->assertNull($transfer->getStatusReason());
		        $this->assertEquals(MiraklMock::INVOICE_BASIC, $transfer->getMiraklId());
		        $this->assertNotNull($transfer->getAccountMapping());
		        $this->assertNull($transfer->getTransferId());
		        $this->assertNull($transfer->getTransactionId());
		        $this->assertNotNull($transfer->getAmount());
		        $this->assertEquals('eur', $transfer->getCurrency());
		        $this->assertNotNull($transfer->getMiraklCreatedDate());
				}
    }

    public function testUpdateFromInvoice()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_INVALID_SHOP
				));
				$invoiceId = $invoice['invoice_id'];

				$transfers = [];
				foreach (StripeTransfer::getInvoiceTypes() as $type) {
        		$transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
        		$this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
						$transfers[] = $transfer;
				}

				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_VALID
				));
				$invoice['invoice_id'] = $invoiceId;
				foreach ($transfers as $transfer) {
        		$transfer = $this->stripeTransferFactory->updateFromInvoice($transfer, $invoice, $transfer->getType());
        		$this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
				}
    }

    public function testInvoiceNoShop()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_INVALID_NO_SHOP
				));

				foreach (StripeTransfer::getInvoiceTypes() as $type) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
		        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
		        $this->assertNotNull($transfer->getStatusReason());
				}
    }

    public function testInvoiceInvalidShop()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_INVALID_SHOP
				));

				foreach (StripeTransfer::getInvoiceTypes() as $type) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
		        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
		        $this->assertNotNull($transfer->getStatusReason());
				}
    }

    public function testInvoiceWithTransfer()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_VALID
				));

				foreach (StripeTransfer::getInvoiceTypes() as $type) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
		        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());

						$transfer->setTransferId(StripeMock::TRANSFER_BASIC);
		        $transfer = $this->stripeTransferFactory->updateFromInvoice($transfer, $invoice, $type);
		        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
				}
    }

    public function testInvoiceInvalidAmount()
    {
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_INVALID_AMOUNT
				));
				foreach (StripeTransfer::getInvoiceTypes() as $type) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
		        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
		        $this->assertNotNull($transfer->getStatusReason());
				}
    }

    public function testInvoiceDifferentAmounts()
    {
				$amounts = [
						StripeTransfer::TRANSFER_SUBSCRIPTION => 999,
						StripeTransfer::TRANSFER_EXTRA_CREDITS => 5678,
						StripeTransfer::TRANSFER_EXTRA_INVOICES => 9876,
				];
				$invoice = current($this->miraklClient->listInvoicesByDate(
						MiraklMock::INVOICE_DATE_1_VALID
				));

				foreach ($amounts as $type => $expectedAmount) {
		        $transfer = $this->stripeTransferFactory->createFromInvoice($invoice, $type);
						$this->assertEquals(
								$expectedAmount,
								$transfer->getAmount(),
								'Expected ' . $expectedAmount . ' for ' . $type
						);
				}
    }
}