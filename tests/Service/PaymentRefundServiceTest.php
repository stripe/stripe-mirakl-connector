<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklProductPendingRefund;
use App\Entity\MiraklServicePendingRefund;
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

    private $paymentMappingRepository;

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
            $stripeClient,
            $this->miraklClient
        );
        $stripeRefundFactory->setLogger(new NullLogger());

        $stripeTransferFactory = new StripeTransferFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class),
            $container->get('doctrine')->getRepository(PaymentMapping::class),
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            $this->miraklClient,
            $stripeClient,
            'acc_xxxxxxx',
            '_TAX',
            false
        );
        $stripeTransferFactory->setLogger(new NullLogger());

        $this->paymentRefundService = new PaymentRefundService(
            $stripeRefundFactory,
            $stripeTransferFactory,
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            '_TAX',
            false
        );
    }

    private function mockConfiguration(bool $enablePaymentTaxSplit)
    {
        $container = self::$kernel->getContainer();

        $stripeClient = $container->get('App\Service\StripeClient');

        $stripeRefundFactory = new StripeRefundFactory(
            $this->paymentMappingRepository,
            $this->stripeTransferRepository,
            $stripeClient,
            $this->miraklClient
        );
        $stripeRefundFactory->setLogger(new NullLogger());

        $stripeTransferFactory = new StripeTransferFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class),
            $container->get('doctrine')->getRepository(PaymentMapping::class),
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            $this->miraklClient,
            $stripeClient,
            'acc_xxxxxxx',
            '_TAX',
            $enablePaymentTaxSplit
        );
        $stripeTransferFactory->setLogger(new NullLogger());

        $this->paymentRefundService = new PaymentRefundService(
            $stripeRefundFactory,
            $stripeTransferFactory,
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            '_TAX',
            $enablePaymentTaxSplit
        );
    }

    public function testReconcileTaxShortfallReversalsRecreatesAndRedispatchesPendingShortfall(): void
    {
        $container = self::$kernel->getContainer();
        $this->miraklClient = new MiraklClient($container->get(MiraklMock::class), '_TAX');
        $this->mockConfiguration(true);

        $refundId = (string) MiraklMock::ORDER_TAX_SPLIT_REFUND_ID;
        $orderId = MiraklMock::ORDER_TAX_SPLIT_REFUND;
        $refund = new StripeRefund();
        $refund->setType(StripeRefund::REFUND_PRODUCT_ORDER);
        $refund->setMiraklRefundId($refundId);
        $refund->setMiraklOrderId($orderId);
        $refund->setMiraklOrderLineId($orderId.'-1');
        $refund->setCurrency('eur');
        $refund->setAmount(1234);
        $refund->setStatus(StripeRefund::REFUND_CREATED);
        $this->stripeRefundRepository->persistAndFlush($refund);

        $secondRefundId = (string) MiraklMock::ORDER_TAX_SPLIT_REFUND_SECOND_ID;
        $secondRefund = new StripeRefund();
        $secondRefund->setType(StripeRefund::REFUND_PRODUCT_ORDER);
        $secondRefund->setMiraklRefundId($secondRefundId);
        $secondRefund->setMiraklOrderId($orderId);
        $secondRefund->setMiraklOrderLineId($orderId.'-1');
        $secondRefund->setCurrency('eur');
        $secondRefund->setAmount(500);
        $secondRefund->setStatus(StripeRefund::REFUND_CREATED);
        $this->stripeRefundRepository->persistAndFlush($secondRefund);

        $sellerOrder = new StripeTransfer();
        $sellerOrder->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $sellerOrder->setMiraklId($orderId);
        $sellerOrder->setAmount(1000);
        $sellerOrder->setCurrency('eur');
        $sellerOrder->setTransferId('tr_legacy_seller');
        $sellerOrder->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($sellerOrder);

        $taxOrder = new StripeTransfer();
        $taxOrder->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $taxOrder->setMiraklId($orderId.'_TAX');
        $taxOrder->setAmount(334);
        $taxOrder->setCurrency('eur');
        $taxOrder->setTransferId('tr_legacy_tax');
        $taxOrder->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($taxOrder);

        $sellerReversal = new StripeTransfer();
        $sellerReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $sellerReversal->setMiraklId($refundId);
        $sellerReversal->setTransactionId('tr_legacy_seller');
        $sellerReversal->setAmount(100);
        $sellerReversal->setCurrency('eur');
        $sellerReversal->setTransferId('tr_legacy_seller_reversal');
        $sellerReversal->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($sellerReversal);

        $secondSellerReversal = new StripeTransfer();
        $secondSellerReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $secondSellerReversal->setMiraklId($secondRefundId);
        $secondSellerReversal->setTransactionId('tr_legacy_seller');
        $secondSellerReversal->setAmount(100);
        $secondSellerReversal->setCurrency('eur');
        $secondSellerReversal->setTransferId('tr_legacy_seller_reversal_2');
        $secondSellerReversal->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($secondSellerReversal);

        $taxReversal = new StripeTransfer();
        $taxReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $taxReversal->setMiraklId($refundId.'_TAX');
        $taxReversal->setTransactionId('tr_legacy_tax');
        $taxReversal->setAmount(284);
        $taxReversal->setCurrency('eur');
        $taxReversal->setTransferId('tr_legacy_tax_reversal');
        $taxReversal->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($taxReversal);

        $secondTaxReversal = new StripeTransfer();
        $secondTaxReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $secondTaxReversal->setMiraklId($secondRefundId.'_TAX');
        $secondTaxReversal->setTransactionId('tr_legacy_tax');
        $secondTaxReversal->setAmount(50);
        $secondTaxReversal->setCurrency('eur');
        $secondTaxReversal->setTransferId('tr_legacy_tax_reversal_2');
        $secondTaxReversal->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($secondTaxReversal);

        $firstRun = $this->paymentRefundService->reconcileTaxShortfallReversals();
        $this->assertCount(2, $firstRun);
        $this->assertSame(800, array_sum(array_map(
            static fn (StripeTransfer $transfer): int => $transfer->getAmount(),
            $firstRun
        )));
        foreach ($firstRun as $shortfall) {
            $this->assertSame(StripeTransfer::TRANSFER_PENDING, $shortfall->getStatus());
        }

        $secondRun = $this->paymentRefundService->reconcileTaxShortfallReversals();
        $this->assertCount(2, $secondRun);
        $this->assertSame(
            array_map(static fn (StripeTransfer $transfer): ?int => $transfer->getId(), $firstRun),
            array_map(static fn (StripeTransfer $transfer): ?int => $transfer->getId(), $secondRun)
        );
        $this->assertCount(2, $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'miraklId' => [$refundId.'_TAX_SHORTFALL', $secondRefundId.'_TAX_SHORTFALL'],
        ]));
    }

    private function mockOrderTransfer(MiraklPendingRefund $order, string $transactionId)
    {
        if (is_a($order, MiraklProductPendingRefund::class)) {
            $type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
        } else {
            $type = StripeTransfer::TRANSFER_SERVICE_ORDER;
        }

        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setMiraklId($order->getOrderId());
        $transfer->setAmount(gmp_intval((string) ($order->getAmount() * 100)));
        $transfer->setCurrency(strtolower($order->getCurrency()));
        $transfer->setTransferId(StripeMock::TRANSFER_BASIC);
        $transfer->setTransactionId($transactionId);
        $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);

        $this->stripeTransferRepository->persistAndFlush($transfer);

        return $transfer;
    }

    private function getBasicProductRefundFromRepository()
    {
        return $this->stripeRefundRepository->findOneBy([
            'miraklRefundId' => MiraklMock::PRODUCT_ORDER_REFUND_BASIC
        ]);
    }

    public function testInvalidZeroDecimalRefundDoesNotStopOtherRefunds(): void
    {
        $valid = $this->miraklClient->listProductPendingRefunds()[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];
        $invalidData = $valid->getOrder();
        $invalidData['id'] = 'invalid_jpy_refund';
        $invalidData['order_id'] = 'invalid_jpy_order';
        $invalidData['order_commercial_id'] = 'invalid_jpy_commercial_order';
        $invalidData['order_line_id'] = 'invalid_jpy_order-1';
        $invalidData['currency_iso_code'] = 'JPY';
        $invalidData['amount'] = 60.5;
        $invalid = new MiraklProductPendingRefund($invalidData);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds([
            $invalid->getId() => $invalid,
            $valid->getId() => $valid,
        ]);

        $this->assertCount(2, $refunds);
        $this->assertSame(StripeRefund::REFUND_ABORTED, $refunds[0]->getStatus());
        $this->assertSame(StripeRefund::REFUND_ON_HOLD, $refunds[1]->getStatus());
        $this->assertNotNull($this->stripeRefundRepository->findOneBy(['miraklRefundId' => $valid->getId()]));
    }

    private function getBasicServiceRefundFromRepository()
    {
        return $this->stripeRefundRepository->findOneBy([
            'miraklRefundId' => MiraklMock::SERVICE_ORDER_REFUND_BASIC
        ]);
    }

    private function mockRefundCreated(StripeRefund $refund)
    {
        $refund->setStripeRefundId(StripeMock::REFUND_BASIC);
        $refund->setMiraklValidationTime(new \Datetime());
        $refund->setStatus(StripeRefund::REFUND_CREATED);
        $refund->setStatusReason(null);

        return $refund;
    }

    private function getProductTransfersFromRepository()
    {
        return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'miraklId' => range(
                MiraklMock::PRODUCT_ORDER_REFUND_BASIC,
                MiraklMock::PRODUCT_ORDER_REFUND_BASIC + 14
            )
        ]);
    }

    private function getServiceTransfersFromRepository()
    {
        return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'miraklId' => range(
                MiraklMock::SERVICE_ORDER_REFUND_BASIC,
                MiraklMock::SERVICE_ORDER_REFUND_BASIC + 14
            )
        ]);
    }

    /**
     * @dataProvider provideGetRetriableProductTransfers
     */
    public function testGetRetriableProductTransfers(bool $enablePaymentTaxSplit, $expectedTransfersCount, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listProductPendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::PRODUCT_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicProductRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedTransfersCount, $transfers);
        $this->assertCount(14, $this->getProductTransfersFromRepository());

        // All except ORDER_REFUND_BASIC are retriable
        $this->assertCount($expectedRefundsCount, $this->paymentRefundService->getRetriableTransfers());
        $this->assertCount(14, $this->getProductTransfersFromRepository());
    }

    public function provideGetRetriableProductTransfers()
    {
        return [
            [true, 28, 27],
            [false, 14, 13],
        ];
    }

    /**
     * @dataProvider provideGetTransfersFromProductOrders
     */
    public function testGetTransfersFromProductOrders(bool $enablePaymentTaxSplit, $expectedTransfersCount, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listProductPendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::PRODUCT_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicProductRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedTransfersCount, $transfers);
        $this->assertCount(14, $this->getProductTransfersFromRepository());

        // All except ORDER_REFUND_BASIC are retriable
        $transfers = $this->paymentRefundService->getRetriableTransfers();
        $this->assertCount($expectedRefundsCount, $transfers);
        $this->assertCount(14, $this->getProductTransfersFromRepository());
    }

    public function provideGetTransfersFromProductOrders()
    {
        return [
            [true, 28, 27],
            [false, 14, 13],
        ];
    }

    public function testTaxSplitRetrySkipsAbortedZeroTaxReversal()
    {
        $this->mockConfiguration(true);

        $refundId = MiraklMock::PRODUCT_ORDER_REFUND_BASIC;
        $orders = $this->miraklClient->listProductPendingRefunds();
        $orderRefunds = [$refundId => $orders[$refundId]];

        $sellerOrderTransfer = $this->mockOrderTransfer($orders[$refundId], StripeMock::CHARGE_BASIC);
        $sellerOrderTransfer->setTransferId('tr_seller');

        $taxOrderTransfer = new StripeTransfer();
        $taxOrderTransfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $taxOrderTransfer->setMiraklId($orders[$refundId]->getOrderId().'_TAX');
        $taxOrderTransfer->setAmount(123);
        $taxOrderTransfer->setCurrency('eur');
        $taxOrderTransfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $taxOrderTransfer->setTransferId('tr_tax');
        $this->stripeTransferRepository->persistAndFlush($taxOrderTransfer);

        $this->paymentRefundService->getRefundsFromOrderRefunds($orderRefunds);
        $initialTransfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orderRefunds);
        $this->assertCount(2, $initialTransfers);
        $this->assertSame(StripeTransfer::TRANSFER_ON_HOLD, $initialTransfers[0]->getStatus());
        $this->assertSame(StripeTransfer::TRANSFER_ON_HOLD, $initialTransfers[1]->getStatus());

        // The refund worker completes before the command prepares the reversals again.
        $this->mockRefundCreated($this->getBasicProductRefundFromRepository());
        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orderRefunds);

        $this->assertCount(2, $transfers);
        $this->assertNotSame($transfers[0]->getId(), $transfers[1]->getId());
        $this->assertSame((string) $refundId, $transfers[0]->getMiraklId());
        $this->assertSame($refundId.'_TAX', $transfers[1]->getMiraklId());
        $this->assertSame('tr_seller', $transfers[0]->getTransactionId());
        $this->assertSame('tr_tax', $transfers[1]->getTransactionId());
        $this->assertSame(1111, $transfers[0]->getAmount());
        $this->assertNull($transfers[1]->getAmount());
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $transfers[0]->getStatus());
        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfers[1]->getStatus());
        $this->assertSame('Amount must be positive, input was: 0', $transfers[1]->getStatusReason());

        $transfers[0]->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
        $this->stripeTransferRepository->flush();

        $retry = $this->paymentRefundService->getTransfersFromOrderRefunds($orderRefunds);
        $this->assertCount(1, $retry);
        $this->assertSame($transfers[0]->getId(), $retry[0]->getId());
        $this->assertSame('tr_seller', $retry[0]->getTransactionId());
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $retry[0]->getStatus());
        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfers[1]->getStatus());
        $this->assertSame(1111, $transfers[0]->getAmount());
    }

    public function testGetRefundsFromProductOrders()
    {
        $orders = $this->miraklClient->listProductPendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::PRODUCT_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicProductRefundFromRepository());
        $this->assertCount(14, $refunds);

        // All except ORDER_REFUND_BASIC are retriable
        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->assertCount(13, $refunds);
    }

    /**
     * @dataProvider provideUpdateProductTransfers
     */
    public function testUpdateProductTransfers(bool $enablePaymentTaxSplit, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listProductPendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::PRODUCT_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->assertCount(14, $refunds);

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedRefundsCount, $transfers);
        $this->assertCount(14, $this->getProductTransfersFromRepository());

        // ORDER_REFUND_BASIC is on hold
        $transfers = $this->paymentRefundService->getRetriableTransfers();
        $transfer = $transfers[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

        // ORDER_REFUND_BASIC is pending
        $this->mockRefundCreated($this->getBasicProductRefundFromRepository());
        $transfers = $this->paymentRefundService->updateTransfers($transfers);
        $transfer = $transfers[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function provideUpdateProductTransfers()
    {
        return [
            [true, 28],
            [false, 14],
        ];
    }

    /**
     * @dataProvider provideGetRetriableServiceTransfers
     */
    public function testGetRetriableServiceTransfers(bool $enablePaymentTaxSplit, $expectedTransfersCount, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listServicePendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::SERVICE_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicServiceRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedTransfersCount, $transfers);
        $this->assertCount(14, $this->getServiceTransfersFromRepository());

        // All except ORDER_REFUND_BASIC are retriable
        $this->assertCount($expectedRefundsCount, $this->paymentRefundService->getRetriableTransfers());
        $this->assertCount(14, $this->getServiceTransfersFromRepository());
    }

    public function provideGetRetriableServiceTransfers()
    {
        return [
            [true, 28, 27],
            [false, 14, 13],
        ];
    }

    /**
     * @dataProvider provideGetTransfersFromServiceOrders
     */
    public function testGetTransfersFromServiceOrders(bool $enablePaymentTaxSplit, $expectedTransfersCount, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listServicePendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::SERVICE_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicServiceRefundFromRepository());

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedTransfersCount, $transfers);
        $this->assertCount(14, $this->getServiceTransfersFromRepository());

        // All except ORDER_REFUND_BASIC are retriable
        $transfers = $this->paymentRefundService->getRetriableTransfers();
        $this->assertCount($expectedRefundsCount, $transfers);
        $this->assertCount(14, $this->getServiceTransfersFromRepository());
    }

    public function provideGetTransfersFromServiceOrders()
    {
        return [
            [true, 28, 27],
            [false, 14, 13],
        ];
    }

    public function testGetRefundsFromServiceOrders()
    {
        $orders = $this->miraklClient->listServicePendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::SERVICE_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->mockRefundCreated($this->getBasicServiceRefundFromRepository());
        $this->assertCount(14, $refunds);

        // All except ORDER_REFUND_BASIC are retriable
        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->assertCount(13, $refunds);
    }

    /**
     * @dataProvider provideUpdateServiceTransfers
     */
    public function testUpdateServiceTransfers(bool $enablePaymentTaxSplit, $expectedRefundsCount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $orders = $this->miraklClient->listServicePendingRefunds();
        $this->mockOrderTransfer($orders[MiraklMock::SERVICE_ORDER_REFUND_BASIC], StripeMock::CHARGE_BASIC);

        $refunds = $this->paymentRefundService->getRefundsFromOrderRefunds($orders);
        $this->assertCount(14, $refunds);

        $transfers = $this->paymentRefundService->getTransfersFromOrderRefunds($orders);
        $this->assertCount($expectedRefundsCount, $transfers);
        $this->assertCount(14, $this->getServiceTransfersFromRepository());

        // ORDER_REFUND_BASIC is on hold
        $transfers = $this->paymentRefundService->getRetriableTransfers();
        $transfer = $transfers[MiraklMock::SERVICE_ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

        // ORDER_REFUND_BASIC is pending
        $this->mockRefundCreated($this->getBasicServiceRefundFromRepository());
        $transfers = $this->paymentRefundService->updateTransfers($transfers);
        $transfer = $transfers[MiraklMock::SERVICE_ORDER_REFUND_BASIC];
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function provideUpdateServiceTransfers()
    {
        return [
            [true, 28],
            [false, 14],
        ];
    }
}
