<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\MiraklProductOrder;
use App\Entity\MiraklServiceOrder;
use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\PaymentMapping;
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
            '_TAX'
        );
        $this->stripeTransferFactory->setLogger(new NullLogger());
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
        $transfer->setAmount(gmp_intval((string) ($order->getAmount() * 100)));
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
        $refund->setAmount(gmp_intval((string) ($order->getAmount() * 100)));
        $refund->setCurrency(strtolower($order->getCurrency()));
        $refund->setTransactionId(StripeMock::CHARGE_BASIC);
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

    public function testCreateFromProductOrder()
    {
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
        $this->assertEquals(7205, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
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

    public function testProductOrderDifferentAmounts()
    {
        $amounts = [
            'NO_COMMISSION' => 7604,
            'NO_TAX' => 6513,
            'TAX_INCLUDED' => 5645,
            'PARTIAL_TAX' => 6959,
            'NO_SALES_TAX' => 7205,
            'NO_SHIPPING_TAX' => 6513
        ];

        foreach ($amounts as $const => $expectedAmount) {
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


    public function testCreateFromServiceOrder()
    {
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
        $this->assertEquals(1081, $transfer->getAmount());
        $this->assertEquals('eur', $transfer->getCurrency());
        $this->assertNotNull($transfer->getMiraklCreatedDate());
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

    public function testServiceOrderDifferentAmounts()
    {
        $amounts = [
            'NO_COMMISSION' => 1480,
            'NO_TAX' => 1081,
            'TAX_INCLUDED' => 1081,
            'PARTIAL_TAX' => 1081,
        ];

        foreach ($amounts as $const => $expectedAmount) {
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
