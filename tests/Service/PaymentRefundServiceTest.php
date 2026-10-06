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
            $stripeClient
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
            $enablePaymentTaxSplit
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

    public function testTaxSplitRetryKeepsSellerAndTaxReversalsSeparate()
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
        $this->assertSame(0, $transfers[1]->getAmount());
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $transfers[0]->getStatus());
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $transfers[1]->getStatus());

        $transfers[0]->setStatus(StripeTransfer::TRANSFER_CREATED);
        $transfers[1]->setStatus(StripeTransfer::TRANSFER_ON_HOLD);
        $this->stripeTransferRepository->flush();

        $retry = $this->paymentRefundService->getTransfersFromOrderRefunds($orderRefunds);
        $this->assertCount(1, $retry);
        $this->assertSame($transfers[1]->getId(), $retry[0]->getId());
        $this->assertSame('tr_tax', $retry[0]->getTransactionId());
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
