<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\MiraklProductOrder;
use App\Entity\MiraklProductPendingRefund;
use App\Entity\MiraklServiceOrder;
use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Factory\StripeTransferFactory;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\StripeAmount;
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

    private $paymentMappingRepository;

    private $stripeRefundRepository;

    private $accountMappingRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

        $this->miraklClient = $container->get('App\Service\MiraklClient');

        $this->paymentMappingRepository = $container->get('doctrine')
            ->getRepository(PaymentMapping::class);
        $this->stripeRefundRepository = $container->get('doctrine')
            ->getRepository(StripeRefund::class);
        $this->stripeTransferRepository = $container->get('doctrine')
            ->getRepository(StripeTransfer::class);
        $this->accountMappingRepository = $container->get('doctrine')
            ->getRepository(AccountMapping::class);

        $this->stripeTransferFactory = new StripeTransferFactory(
            $this->accountMappingRepository,
            $this->paymentMappingRepository,
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            $this->miraklClient,
            $container->get('App\Service\StripeClient'),
            'acc_xxxxxxx',
            '_TAX',
            false
        );
        $this->stripeTransferFactory->setLogger(new NullLogger());
    }

    private function mockConfiguration(bool $enablePaymentTaxSplit)
    {
        $container = self::$kernel->getContainer();
        $this->stripeTransferFactory = new StripeTransferFactory(
            $this->accountMappingRepository,
            $this->paymentMappingRepository,
            $this->stripeRefundRepository,
            $this->stripeTransferRepository,
            $this->miraklClient,
            $container->get('App\Service\StripeClient'),
            'acc_xxxxxxx',
            '_TAX',
            $enablePaymentTaxSplit
        );
        $this->stripeTransferFactory->setLogger(new NullLogger());
    }

    private function mockTaxSplitConfiguration(): void
    {
        $container = self::$kernel->getContainer();
        $this->miraklClient = new MiraklClient($container->get(MiraklMock::class), '_TAX');
        $this->mockConfiguration(true);
    }

    private function mockOrderTransfer(MiraklPendingRefund $order)
    {
        if (is_a($order, MiraklServicePendingRefund::class)) {
            $type = StripeTransfer::TRANSFER_SERVICE_ORDER;
        } else {
            $type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
        }

        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setMiraklId($order->getOrderId());
        $transfer->setAmount(StripeAmount::toMinorUnits($order->getAmount(), $order->getCurrency()));
        $transfer->setCurrency(strtolower($order->getCurrency()));
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

    private function mockStripeRefund(MiraklPendingRefund $order)
    {
        if (is_a($order, MiraklServicePendingRefund::class)) {
            $type = StripeRefund::REFUND_SERVICE_ORDER;
        } else {
            $type = StripeRefund::REFUND_PRODUCT_ORDER;
        }

        $refund = new StripeRefund();
        $refund->setType($type);
        $refund->setMiraklOrderId($order->getOrderId());
        $refund->setMiraklOrderLineId($order->getOrderLineId());
        $refund->setAmount(StripeAmount::toMinorUnits($order->getAmount(), $order->getCurrency()));
        $refund->setCurrency(strtolower($order->getCurrency()));
        $refund->setTransactionId(
            strtolower($order->getCurrency()) === 'jpy' ? StripeMock::CHARGE_JPY : StripeMock::CHARGE_BASIC
        );
        $refund->setMiraklRefundId($order->getId());
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

    private function mockPaymentMapping(string $orderId, string $chargeId)
    {
        $paymentMapping = new PaymentMapping();
        $paymentMapping->setMiraklCommercialOrderId($orderId);
        $paymentMapping->setStripeChargeId($chargeId);

        $this->paymentMappingRepository->persist($paymentMapping);
        $this->paymentMappingRepository->flush();

        return $paymentMapping;
    }

    /**
     * @dataProvider provideCreateFromProductOrder
     */
    public function testCreateFromProductOrder($enablePaymentTaxSplit, $expectedAmount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listProductOrdersById([
                MiraklMock::ORDER_BASIC
            ]))
        );

        $this->assertEquals(StripeTransfer::TRANSFER_PRODUCT_ORDER, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::ORDER_BASIC, $transfer->getMiraklId());
        $this->assertNotNull($transfer->getAccountMapping());
        $this->assertNull($transfer->getTransferId());
        $this->assertNull($transfer->getTransactionId());
        $this->assertEquals($expectedAmount, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
    }

    public function testFractionalZeroDecimalOrderAmountAbortsTransfer(): void
    {
        $order = current($this->miraklClient->listProductOrdersById([MiraklMock::ORDER_BASIC]));
        $data = $order->getOrder();
        $data['currency_iso_code'] = 'JPY';
        $data['total_price'] = 100;
        $data['total_commission'] = 0.5;
        $data['order_lines'] = [];

        $transfer = $this->stripeTransferFactory->createFromOrder(new MiraklProductOrder($data));

        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNull($transfer->getAmount());
        $this->assertStringContainsString('JPY amounts must be whole currency units', $transfer->getStatusReason());
    }

    public function testFractionalZeroDecimalOrderAndCommissionCannotCancelOut(): void
    {
        $order = current($this->miraklClient->listProductOrdersById([MiraklMock::ORDER_BASIC]));
        $data = $order->getOrder();
        $data['currency_iso_code'] = 'JPY';
        $data['total_price'] = 100.5;
        $data['total_commission'] = 0.5;
        $data['order_lines'] = [];

        $transfer = $this->stripeTransferFactory->createFromOrder(new MiraklProductOrder($data));

        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNull($transfer->getAmount());
        $this->assertStringContainsString('JPY amounts must be whole currency units', $transfer->getStatusReason());
    }

    public function provideCreateFromProductOrder()
    {
        return [
            [true, 6513],
            [false, 8073],
        ];
    }

    public function testTaxSplitTransfersIncludeShippingTaxes(): void
    {
        $this->mockConfiguration(true);
        $this->addTaxAccountMapping();
        $order = current($this->miraklClient->listProductOrdersById([MiraklMock::ORDER_BASIC]));

        $sellerTransfer = $this->stripeTransferFactory->createFromOrder($order);
        $taxTransfer = $this->stripeTransferFactory->createFromOrderForTax($order);

        $this->assertSame(6513, $sellerTransfer->getAmount());
        $this->assertSame(1560, $taxTransfer->getAmount());
        $this->assertSame(8073, $sellerTransfer->getAmount() + $taxTransfer->getAmount());
    }

    public function testTaxSplitRefundFlowUsesZeroDecimalCurrencyUnits(): void
    {
        $this->mockTaxSplitConfiguration();
        $this->addTaxAccountMapping();
        $order = current($this->miraklClient->listProductOrdersById([MiraklMock::ORDER_TAX_SPLIT_JPY]));
        $this->assertSame('JPY', $order->getCurrency());

        $sellerOrderTransfer = $this->stripeTransferFactory->createFromOrder($order);
        $sellerOrderTransfer->setTransferId('tr_jpy_seller');
        $sellerOrderTransfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($sellerOrderTransfer);

        $taxOrderTransfer = $this->stripeTransferFactory->createFromOrderForTax($order);
        $taxOrderTransfer->setTransferId('tr_jpy_tax');
        $taxOrderTransfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($taxOrderTransfer);

        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) MiraklMock::ORDER_TAX_SPLIT_JPY_REFUND_ID,
            'order_id' => MiraklMock::ORDER_TAX_SPLIT_JPY,
            'order_commercial_id' => MiraklMock::ORDER_TAX_SPLIT_JPY,
            'order_line_id' => MiraklMock::ORDER_TAX_SPLIT_JPY . '-1',
            'amount' => 60,
            'currency_iso_code' => 'JPY',
        ]);
        $refund = $this->mockStripeRefund($pendingRefund);
        $this->mockRefundCreated($refund);
        $this->assertSame(1.0, $order->getRefundedOperatorCommission($refund));
        $this->assertSame(3.0, $order->getRefundedTax($refund));
        $taxLookupOrder = current($this->miraklClient->listProductOrdersById([$pendingRefund->getOrderId() . '_TAX']));
        $this->assertSame('JPY', $taxLookupOrder->getCurrency());
        $this->assertSame(3.0, $taxLookupOrder->getRefundedTax($refund));

        $sellerReversal = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);
        $taxReversal = $this->stripeTransferFactory->createFromOrderRefundForTax($pendingRefund);

        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $sellerReversal->getStatus());
        $this->assertSame(56, $sellerReversal->getAmount());
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $taxReversal->getStatus());
        $this->assertSame(3, $taxReversal->getAmount());
        $this->assertSame('jpy', $taxReversal->getCurrency());
    }

    private function addTaxAccountMapping(): void
    {
        $mapping = new AccountMapping();
        $mapping->setMiraklShopId(9999);
        $mapping->setStripeAccountId('acc_xxxxxxx');
        $this->accountMappingRepository->persistAndFlush($mapping);
    }

    public function testUpdateFromProductOrder()
    {
        $order = current($this->miraklClient->listProductOrdersById([
            MiraklMock::ORDER_STATUS_STAGING
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

        $order = current($this->miraklClient->listProductOrdersById([
            MiraklMock::ORDER_STATUS_SHIPPING
        ]));
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testProductOrderWithTransactionNumber()
    {
        $order = current($this->miraklClient->listProductOrdersById([
            MiraklMock::ORDER_WITH_TRANSACTION_NUMBER
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertEquals(StripeMock::CHARGE_BASIC, $transfer->getTransactionId());
    }

    public function testProductOrderWithPaymentMapping()
    {
        $order = current($this->miraklClient->listProductOrdersById([
            MiraklMock::ORDER_BASIC
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getTransactionId());

        $this->mockPaymentMapping(MiraklMock::ORDER_BASIC, StripeMock::CHARGE_BASIC);
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
        $this->assertEquals(StripeMock::CHARGE_BASIC, $transfer->getTransactionId());
    }

    public function testProductOrderWithTransfer()
    {
        $order = current($this->miraklClient->listProductOrdersById([
            MiraklMock::ORDER_BASIC
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order);
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
                    ]))
                );
                $this->assertEquals(
                    $expectedTransferStatus,
                    $transfer->getStatus(),
                    "Expected $expectedTransferStatus for $const."
                );
            }
        }
    }

    public function testProductOrderInvalidShop()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listProductOrdersById([
                MiraklMock::ORDER_INVALID_SHOP
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testProductOrderIgnoredShop()
    {
        $accountMapping = new AccountMapping();
        $accountMapping->setMiraklShopId(MiraklMock::SHOP_EXISTING_IGNORED);
        $accountMapping->setStripeAccountId(StripeMock::ACCOUNT_NEW);
        $accountMapping->setIgnored(true);
        $this->accountMappingRepository->persistAndFlush($accountMapping);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listProductOrdersById([
                MiraklMock::ORDER_IGNORED_SHOP
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_IGNORED, $transfer->getStatus());
    }

    public function testProductOrderInvalidAmount()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listProductOrdersById([
                MiraklMock::ORDER_INVALID_AMOUNT
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    /**
     * @dataProvider provideProductOrderDifferentAmounts
     */
    public function testProductOrderDifferentAmounts(bool $enablePaymentTaxSplit, $const, $expectedAmount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listProductOrdersById([
                constant("App\Tests\MiraklMockedHttpClient::ORDER_AMOUNT_$const")
            ]))
        );

        $this->assertEquals(
            $expectedAmount,
            $transfer->getAmount(),
            'Expected ' . $expectedAmount . ' for ' . $const
        );
    }

    public function provideProductOrderDifferentAmounts()
    {
        return [
            [true, 'NO_COMMISSION', 6912],
            [true, 'NO_TAX', 6513],
            [true, 'TAX_INCLUDED', 4953],
            [true, 'PARTIAL_TAX', 6513],
            [true, 'NO_SALES_TAX', 6513],
            [true, 'NO_SHIPPING_TAX', 6513],
            [false, 'NO_COMMISSION', 8472],
            [false, 'NO_TAX', 6513],
            [false, 'TAX_INCLUDED', 6513],
            [false, 'PARTIAL_TAX', 7293],
            [false, 'NO_SALES_TAX', 7205],
            [false, 'NO_SHIPPING_TAX', 7381],
        ];
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

        $order = current($this->miraklClient->listProductOrdersById(
            [MiraklMock::ORDER_BASIC]
        ))->getOrder();
        foreach ($chargeStatuses as $expectedTransferStatus => $consts) {
            foreach ($consts as $const) {
                $order['transaction_number'] = constant("App\Tests\StripeMockedHttpClient::$const");
                $transfer = $this->stripeTransferFactory->createFromOrder(new MiraklProductOrder($order));
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
                'PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE', 'PAYMENT_INTENT_WITHOUT_CHARGE'
            ],
            StripeTransfer::TRANSFER_PENDING => [
                'PAYMENT_INTENT_BASIC', 'PAYMENT_INTENT_STATUS_SUCCEEDED', 'PAYMENT_INTENT_WITH_CHARGE_ID'
            ],
            StripeTransfer::TRANSFER_ABORTED => [
                'PAYMENT_INTENT_STATUS_CANCELED',
                'PAYMENT_INTENT_REFUNDED',
                'PAYMENT_INTENT_NOT_FOUND'
            ]
        ];

        $order = current($this->miraklClient->listProductOrdersById(
            [MiraklMock::ORDER_BASIC]
        ))->getOrder();
        foreach ($chargeStatuses as $expectedTransferStatus => $consts) {
            foreach ($consts as $const) {
                $order['transaction_number'] = constant("App\Tests\StripeMockedHttpClient::$const");
                $transfer = $this->stripeTransferFactory->createFromOrder(new MiraklProductOrder($order));
                $this->assertEquals(
                    $expectedTransferStatus,
                    $transfer->getStatus(),
                    'Expected ' . $expectedTransferStatus . ' for ' . $const
                );
            }
        }
    }

    /**
     * @dataProvider provideCreateFromServiceOrder
     */
    public function testCreateFromServiceOrder(bool $enablePaymentTaxSplit, $expectedAmount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listServiceOrdersById([
                MiraklMock::ORDER_BASIC
            ])),
            current($this->miraklClient->listServicePendingDebitsByOrderIds([
                MiraklMock::ORDER_BASIC
            ]))
        );

        $this->assertEquals(StripeTransfer::TRANSFER_SERVICE_ORDER, $transfer->getType());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertNull($transfer->getStatusReason());
        $this->assertEquals(MiraklMock::ORDER_BASIC, $transfer->getMiraklId());
        $this->assertNotNull($transfer->getAccountMapping());
        $this->assertNull($transfer->getTransferId());
        $this->assertNull($transfer->getTransactionId());
        $this->assertEquals($expectedAmount, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
    }

    public function provideCreateFromServiceOrder()
    {
        return [
            [true, 1081],
            [false, 1415],
        ];
    }

    public function testUpdateFromServiceOrder()
    {
        $order = current($this->miraklClient->listServiceOrdersById([
            MiraklMock::ORDER_STATUS_WAITING_SCORING
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order);
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

        $order = current($this->miraklClient->listServiceOrdersById([
            MiraklMock::ORDER_STATUS_ORDER_ACCEPTED
        ]));
        $pendingDebit = current($this->miraklClient->listServicePendingDebitsByOrderIds([
            MiraklMock::ORDER_STATUS_ORDER_ACCEPTED
        ]));
        $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order, $pendingDebit);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
    }

    public function testServiceOrderWithTransfer()
    {
        $order = current($this->miraklClient->listServiceOrdersById([
            MiraklMock::ORDER_BASIC
        ]));
        $pendingDebit = current($this->miraklClient->listServicePendingDebitsByOrderIds([
            MiraklMock::ORDER_BASIC
        ]));

        $transfer = $this->stripeTransferFactory->createFromOrder($order, $pendingDebit);
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
                    current($this->miraklClient->listServicePendingDebitsByOrderIds([
                        constant("App\Tests\MiraklMockedHttpClient::ORDER_STATUS_$const")
                    ]))
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
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    public function testServiceOrderIgnoredShop()
    {
        $accountMapping = new AccountMapping();
        $accountMapping->setMiraklShopId(MiraklMock::SHOP_EXISTING_IGNORED);
        $accountMapping->setStripeAccountId(StripeMock::ACCOUNT_NEW);
        $accountMapping->setIgnored(true);
        $this->accountMappingRepository->persistAndFlush($accountMapping);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listServiceOrdersById([
                MiraklMock::ORDER_IGNORED_SHOP
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_IGNORED, $transfer->getStatus());
    }

    public function testServiceOrderInvalidAmount()
    {
        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listServiceOrdersById([
                MiraklMock::ORDER_INVALID_AMOUNT
            ])),
            current($this->miraklClient->listServicePendingDebitsByOrderIds([
                MiraklMock::ORDER_INVALID_AMOUNT
            ]))
        );
        $this->assertEquals(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
    }

    /**
     * @dataProvider provideServiceOrderDifferentAmounts
     */
    public function testServiceOrderDifferentAmounts(bool $enablePaymentTaxSplit, $const, $expectedAmount)
    {
        $this->mockConfiguration($enablePaymentTaxSplit);

        $transfer = $this->stripeTransferFactory->createFromOrder(
            current($this->miraklClient->listServiceOrdersById([
                constant("App\Tests\MiraklMockedHttpClient::ORDER_AMOUNT_$const")
            ])),
            current($this->miraklClient->listServicePendingDebitsByOrderIds([
                constant("App\Tests\MiraklMockedHttpClient::ORDER_AMOUNT_$const")
            ]))
        );

        $this->assertEquals(
            $expectedAmount,
            $transfer->getAmount(),
            'Expected ' . $expectedAmount . ' for ' . $const
        );
    }

    public function provideServiceOrderDifferentAmounts()
    {
        return [
            [true, 'NO_COMMISSION', 1480],
            [true, 'NO_TAX', 1081],
            [true, 'TAX_INCLUDED', 1081],
            [true, 'PARTIAL_TAX', 1081],
            [false, 'NO_COMMISSION', 1814],
            [false, 'NO_TAX', 1081],
            [false, 'TAX_INCLUDED', 1081],
            [false, 'PARTIAL_TAX', 1237],
        ];
    }

    public function testCreateFromProductOrderRefund()
    {
        $pendingRefunds = $this->miraklClient->listProductPendingRefunds();
        $pendingRefund = $pendingRefunds[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];

        $orderTransfer = $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            MiraklMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));
        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

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

    public function testFractionalZeroDecimalRefundCommissionAbortsReversal(): void
    {
        $refundId = MiraklMock::PRODUCT_ORDER_REFUND_JPY_FRACTIONAL_COMMISSION;
        $orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId);
        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) $refundId,
            'order_id' => $orderId,
            'order_commercial_id' => $orderId,
            'order_line_id' => $orderId.'-1',
            'amount' => 60.0,
            'currency_iso_code' => 'JPY',
        ]);
        $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            StripeMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));

        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNull($transfer->getAmount());
        $this->assertStringContainsString('JPY amounts must be whole currency units', $transfer->getStatusReason());
    }

    public function testZeroDecimalRefundReversalUsesMatchingOrderCurrency(): void
    {
        $refundId = MiraklMock::PRODUCT_ORDER_REFUND_JPY_BASIC;
        $orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId);
        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) $refundId,
            'order_id' => $orderId,
            'order_commercial_id' => MiraklMock::getCommercialIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId),
            'order_line_id' => $orderId.'-1',
            'amount' => 60.0,
            'currency_iso_code' => 'JPY',
        ]);
        $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            StripeMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));

        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertSame(59, $transfer->getAmount());
        $this->assertSame('jpy', $transfer->getCurrency());
    }

    /**
     * @dataProvider provideNonPositiveZeroDecimalRefundReversals
     */
    public function testNonPositiveZeroDecimalRefundReversalIsAborted(int $refundId, int $amount): void
    {
        $orderId = MiraklMock::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId);
        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) $refundId,
            'order_id' => $orderId,
            'order_commercial_id' => MiraklMock::getCommercialIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId),
            'order_line_id' => $orderId.'-1',
            'amount' => 1.0,
            'currency_iso_code' => 'JPY',
        ]);
        $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            StripeMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));

        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertNull($transfer->getAmount());
        $this->assertSame(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT, $amount), $transfer->getStatusReason());
    }

    public function provideNonPositiveZeroDecimalRefundReversals(): array
    {
        return [
            [MiraklMock::PRODUCT_ORDER_REFUND_JPY_ZERO_REVERSAL, 0],
            [MiraklMock::PRODUCT_ORDER_REFUND_JPY_NEGATIVE_REVERSAL, -1],
        ];
    }

    public function testRefundReversalRejectsCurrencyMismatch(): void
    {
        $pendingRefund = $this->miraklClient->listProductPendingRefunds()[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];
        $data = $pendingRefund->getOrder();
        $data['currency_iso_code'] = 'JPY';
        $data['amount'] = 60.0;
        $pendingRefund = new MiraklProductPendingRefund($data);
        $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            StripeMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));

        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

        $this->assertSame(StripeTransfer::TRANSFER_ABORTED, $transfer->getStatus());
        $this->assertSame('Refund, order, and original transfer currencies differ.', $transfer->getStatusReason());
    }

    public function testUpdateProductOrderRefundTransfer()
    {
        $pendingRefunds = $this->miraklClient->listProductPendingRefunds();
        $pendingRefund = $pendingRefunds[MiraklMock::PRODUCT_ORDER_REFUND_BASIC];

        $orderTransfer = $this->mockOrderTransfer($pendingRefund);
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));
        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

        $this->assertEquals(StripeTransfer::TRANSFER_ON_HOLD, $transfer->getStatus());

        // Order transfer has been processed
        $this->mockOrderTransferCreated($orderTransfer, StripeMock::TRANSFER_BASIC);

        $transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertSame(1111, $transfer->getAmount());
    }

    public function testTaxRefundAgainstLegacyTaxTransferIsCapped(): void
    {
        $this->mockTaxSplitConfiguration();
        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) MiraklMock::ORDER_TAX_SPLIT_REFUND_ID,
            'order_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND,
            'order_commercial_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND,
            'order_line_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND . '-1',
            'amount' => 12.34,
            'currency_iso_code' => 'EUR',
        ]);

        // This models an order split made before shipping taxes were included
        // in the tax-account transfer (product taxes only: 3.34 EUR).
        $legacyTaxTransfer = new StripeTransfer();
        $legacyTaxTransfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $legacyTaxTransfer->setMiraklId($pendingRefund->getOrderId() . '_TAX');
        $legacyTaxTransfer->setAmount(334);
        $legacyTaxTransfer->setCurrency('eur');
        $legacyTaxTransfer->setTransferId('tr_legacy_tax');
        $legacyTaxTransfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($legacyTaxTransfer);

        $legacySellerTransfer = new StripeTransfer();
        $legacySellerTransfer->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $legacySellerTransfer->setMiraklId($pendingRefund->getOrderId());
        $legacySellerTransfer->setAmount(1000);
        $legacySellerTransfer->setCurrency('eur');
        $legacySellerTransfer->setTransferId('tr_legacy_seller');
        $legacySellerTransfer->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($legacySellerTransfer);

        $refund = $this->mockStripeRefund($pendingRefund);
        $this->mockRefundCreated($refund);
        $order = current($this->miraklClient->listProductOrdersById([$pendingRefund->getOrderId()]));
        $this->assertSame(5.8, $order->getRefundedTax($refund));

        $taxReversal = $this->stripeTransferFactory->createFromOrderRefundForTax($pendingRefund);

        // Reverse only the legacy tax transfer's 3.34 EUR; the seller-side
        // shortfall recovery handles the 2.46 EUR shipping-tax difference.
        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $taxReversal->getStatus());
        $this->assertSame(334, $taxReversal->getAmount());

        $sellerReversal = new StripeTransfer();
        $sellerReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $sellerReversal->setMiraklId((string) $pendingRefund->getId());
        $sellerReversal->setTransactionId('tr_legacy_seller');
        $sellerReversal->setAmount(531);
        $sellerReversal->setCurrency('eur');
        $sellerReversal->setTransferId('tr_legacy_seller_reversal');
        $sellerReversal->setStatus(StripeTransfer::TRANSFER_CREATED);
        $this->stripeTransferRepository->persistAndFlush($sellerReversal);

        $shortfallReversal = $this->stripeTransferFactory->createTaxShortfallReversal(
            (string) $pendingRefund->getId(),
            $sellerReversal
        );
        $this->assertNotNull($shortfallReversal);
        $this->assertSame(246, $shortfallReversal->getAmount());
        $this->assertSame('tr_legacy_seller', $shortfallReversal->getTransactionId());
    }

    public function testTaxShortfallReservationLocksOrderTransfersBeforeCheckingCapacity(): void
    {
        $this->mockTaxSplitConfiguration();
        $pendingRefund = new MiraklProductPendingRefund([
            'id' => (string) MiraklMock::ORDER_TAX_SPLIT_REFUND_ID,
            'order_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND,
            'order_commercial_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND,
            'order_line_id' => MiraklMock::ORDER_TAX_SPLIT_REFUND.'-1',
            'amount' => 12.34,
            'currency_iso_code' => 'EUR',
        ]);
        $refund = $this->mockStripeRefund($pendingRefund);
        $this->mockRefundCreated($refund);

        $sellerOrder = new StripeTransfer();
        $sellerOrder->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $sellerOrder->setMiraklId($pendingRefund->getOrderId());
        $sellerOrder->setAmount(1000);
        $sellerOrder->setCurrency('eur');
        $sellerOrder->setTransferId('tr_locked_seller');
        $sellerOrder->setStatus(StripeTransfer::TRANSFER_CREATED);

        $taxOrder = new StripeTransfer();
        $taxOrder->setType(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $taxOrder->setMiraklId($pendingRefund->getOrderId().'_TAX');
        $taxOrder->setAmount(334);
        $taxOrder->setCurrency('eur');
        $taxOrder->setTransferId('tr_locked_tax');
        $taxOrder->setStatus(StripeTransfer::TRANSFER_CREATED);

        $sellerReversal = new StripeTransfer();
        $sellerReversal->setType(StripeTransfer::TRANSFER_REFUND);
        $sellerReversal->setMiraklId((string) $pendingRefund->getId());
        $sellerReversal->setTransactionId('tr_locked_seller');
        $sellerReversal->setAmount(531);
        $sellerReversal->setCurrency('eur');
        $sellerReversal->setStatus(StripeTransfer::TRANSFER_CREATED);

        $locked = false;
        $repository = $this->createMock(StripeTransferRepository::class);
        $repository->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $operation) => $operation()
        );
        $repository->expects($this->once())
            ->method('lockOrderTransfersByMiraklIds')
            ->with([$pendingRefund->getOrderId(), $pendingRefund->getOrderId().'_TAX'])
            ->willReturnCallback(static function () use (&$locked): void {
                $locked = true;
            });
        $repository->method('findTransfersByRefundIds')
            ->willReturn([(string) $pendingRefund->getId() => $sellerReversal]);
        $repository->method('findTransfersByOrderIds')->willReturnCallback(
            static fn (array $ids): array => [$ids[0] => str_ends_with($ids[0], '_TAX') ? $taxOrder : $sellerOrder]
        );
        $repository->method('findOneBy')->willReturn(null);
        $repository->method('getReservedRefundAmountForTransaction')->willReturnCallback(
            function () use (&$locked): int {
                $this->assertTrue($locked, 'Capacity must be read only after the source transfers are locked.');

                return 0;
            }
        );
        $repository->method('persist')->willReturnCallback(static fn (StripeTransfer $transfer): StripeTransfer => $transfer);

        $factory = new StripeTransferFactory(
            $this->accountMappingRepository,
            $this->paymentMappingRepository,
            $this->stripeRefundRepository,
            $repository,
            $this->miraklClient,
            self::$kernel->getContainer()->get('App\Service\StripeClient'),
            'acc_xxxxxxx',
            '_TAX',
            true
        );
        $factory->setLogger(new NullLogger());

        $shortfall = $factory->createTaxShortfallReversal(
            (string) $pendingRefund->getId(),
            $sellerReversal
        );

        $this->assertNotNull($shortfall);
        $this->assertSame(246, $shortfall->getAmount());
    }

    public function testCreateFromServiceOrderRefund()
    {
        $pendingRefunds = $this->miraklClient->listServicePendingRefunds();
        $pendingRefund = $pendingRefunds[MiraklMock::SERVICE_ORDER_REFUND_BASIC];

        $orderTransfer = $this->mockOrderTransferCreated(
            $this->mockOrderTransfer($pendingRefund),
            MiraklMock::TRANSFER_BASIC
        );
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));
        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

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
        $pendingRefund = $pendingRefunds[MiraklMock::SERVICE_ORDER_REFUND_BASIC];

        $orderTransfer = $this->mockOrderTransfer($pendingRefund);
        $this->mockRefundCreated($this->mockStripeRefund($pendingRefund));
        $transfer = $this->stripeTransferFactory->createFromOrderRefund($pendingRefund);

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

    public function testCreateFromZeroDecimalInvoice(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMock::INVOICE_DATE_1_VALID
        ));
        $invoice['currency_iso_code'] = 'JPY';
        $invoice['summary']['total_other_credits_incl_tax'] = 60;

        $transfer = $this->stripeTransferFactory->createFromInvoice(
            $invoice,
            StripeTransfer::TRANSFER_EXTRA_CREDITS
        );

        $this->assertSame(StripeTransfer::TRANSFER_PENDING, $transfer->getStatus());
        $this->assertSame(60, $transfer->getAmount());
        $this->assertSame('jpy', $transfer->getCurrency());
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
