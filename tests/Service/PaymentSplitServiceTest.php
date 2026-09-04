<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Entity\MiraklOrder;
use App\Entity\MiraklProductOrder;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Factory\StripeTransferFactory;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\PaymentSplitService;
use App\Tests\MiraklMockedHttpClient;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class PaymentSplitServiceTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentSplitService
     */
    private $paymentSplitService;

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
        $this->stripeTransferRepository = $container->get('doctrine')->getRepository(StripeTransfer::class);

        $stripeTransferFactory = new StripeTransferFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class),
            $container->get('doctrine')->getRepository(PaymentMapping::class),
            $container->get('doctrine')->getRepository(StripeRefund::class),
            $this->stripeTransferRepository,
            $this->miraklClient,
            $container->get('App\Service\StripeClient'),
            'acc_xxxxxxx',
            '_TAX',
            false
        );
        $stripeTransferFactory->setLogger(new NullLogger());

        $this->paymentSplitService = new PaymentSplitService(
            $stripeTransferFactory,
            $this->stripeTransferRepository,
            false,
            '_TAX'
        );
    }

    private function getTransfersFromRepository()
    {
        return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::TRANSFER_PRODUCT_ORDER
        ]);
    }

    public function testGetRetriableTransfers()
    {
        $transfers = $this->paymentSplitService->getTransfersFromOrders(
            $this->miraklClient->listProductOrdersById([
                MiraklMockedHttpClient::ORDER_STATUS_STAGING,
                MiraklMockedHttpClient::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
                MiraklMockedHttpClient::ORDER_STATUS_REFUSED,
                MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
            ]),
            []
        );
        $this->assertCount(4, $transfers);

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(4, $transfers);

        // Only STAGING and WAITING_DEBIT_PAYMENT are retriable
        $transfers = $this->paymentSplitService->getRetriableProductTransfers();
        $this->assertCount(2, $transfers);
    }

    public function testGetTransfersFromOrders()
    {
        $transfers = $this->paymentSplitService->getTransfersFromOrders(
            $this->miraklClient->listProductOrdersById([
                MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
                MiraklMockedHttpClient::ORDER_STATUS_REFUSED
            ]),
            []
        );
        $this->assertCount(2, $transfers);

        $transfers = $this->paymentSplitService->getTransfersFromOrders(
            $this->miraklClient->listProductOrdersById([
                MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
                MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
            ]),
            []
        );
        // SHIPPING is already pending
        $this->assertCount(1, $transfers);

        $transfers = $this->paymentSplitService->getTransfersFromOrders(
            $this->miraklClient->listProductOrdersById([
                MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
                MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
            ]),
            []
        );
        // Both are already pending
        $this->assertCount(0, $transfers);

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(3, $transfers);
    }

    public function testGetTransfersFromOrdersDoesNotReuseTaxTransferForExistingOrder()
    {
        $factory = $this->createMock(StripeTransferFactory::class);
        $repository = $this->createMock(StripeTransferRepository::class);
        $service = new PaymentSplitService($factory, $repository, true, '_TAX');

        // The first order is new and creates a tax transfer. The second order
        // has an existing retriable transfer and must not inherit the first
        // order's tax transfer.
        $firstOrder = $this->createMock(MiraklOrder::class);
        $secondOrder = $this->createMock(MiraklOrder::class);
        $firstTransfer = new StripeTransfer();
        $taxTransfer = new StripeTransfer();
        $existingSecondTransfer = new StripeTransfer();
        $existingSecondTransfer->setStatus(StripeTransfer::TRANSFER_FAILED);
        $updatedSecondTransfer = new StripeTransfer();

        $repository->expects($this->once())
            ->method('findTransfersByOrderIds')
            ->with(['order-1', 'order-2'])
            ->willReturn(['order-2' => $existingSecondTransfer]);
        $repository->expects($this->exactly(2))
            ->method('persist')
            ->withConsecutive([$firstTransfer], [$taxTransfer]);
        $repository->expects($this->once())->method('flush');

        $factory->expects($this->once())
            ->method('createFromOrder')
            ->with($firstOrder, null)
            ->willReturn($firstTransfer);
        $factory->expects($this->once())
            ->method('createFromOrderForTax')
            ->with($firstOrder, null)
            ->willReturn($taxTransfer);
        $factory->expects($this->once())
            ->method('updateFromOrder')
            ->with($existingSecondTransfer, $secondOrder, null)
            ->willReturn($updatedSecondTransfer);

        $transfers = $service->getTransfersFromOrders(
            ['order-1' => $firstOrder, 'order-2' => $secondOrder],
            []
        );

        $this->assertSame([$firstTransfer, $taxTransfer, $updatedSecondTransfer], $transfers);
    }

    public function testUpdateTransfersFromOrders()
    {
        $orders = $this->miraklClient->listProductOrdersById([
            MiraklMockedHttpClient::ORDER_STATUS_STAGING,
            MiraklMockedHttpClient::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
            MiraklMockedHttpClient::ORDER_STATUS_REFUSED,
            MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
        ]);
        $transfers = $this->paymentSplitService->getTransfersFromOrders($orders, []);
        $this->assertCount(4, $transfers);

        // Only STAGING and WAITING_DEBIT_PAYMENT are retriable
        $transfers = $this->paymentSplitService->getRetriableProductTransfers();
        $this->assertCount(2, $transfers);
        unset($orders[MiraklMockedHttpClient::ORDER_STATUS_REFUSED]);
        unset($orders[MiraklMockedHttpClient::ORDER_STATUS_RECEIVED]);

        // STAGING moved to SHIPPING \o/
        $id = MiraklMockedHttpClient::ORDER_STATUS_STAGING;
        $order = $orders[$id]->getOrder();
        $order['order_state'] = 'SHIPPING';
        $order['order_lines'][0]['order_line_state'] = 'SHIPPING';
        $order['order_lines'][1]['order_line_state'] = 'SHIPPING';
        $orders[$id] = new MiraklProductOrder($order);
        $transfers = $this->paymentSplitService->updateTransfersFromOrders($transfers, $orders, []);
        $this->assertCount(2, $transfers);

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(4, $transfers);
    }
}
