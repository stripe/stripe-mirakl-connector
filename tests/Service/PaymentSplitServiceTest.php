<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
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
						$container->get('doctrine')->getRepository(StripeRefund::class),
						$this->stripeTransferRepository,
						$this->miraklClient,
						$container->get('App\Service\StripeClient')
				);
        $stripeTransferFactory->setLogger(new NullLogger());

        $this->paymentSplitService = new PaymentSplitService(
						$stripeTransferFactory,
						$this->stripeTransferRepository
				);
    }

		private function getTransfersFromRepository() {
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
						MiraklClient::ORDER_TYPE_PRODUCT
				);
        $this->assertCount(4, $transfers);

				$transfers = $this->getTransfersFromRepository();
        $this->assertCount(4, $transfers);

				// Only STAGING and WAITING_DEBIT_PAYMENT are retriable
        $transfers = $this->paymentSplitService->getRetriableTransfers(MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(2, $transfers);
    }

    public function testGetTransfersFromOrders()
    {
        $transfers = $this->paymentSplitService->getTransfersFromOrders(
						$this->miraklClient->listProductOrdersById([
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
								MiraklMockedHttpClient::ORDER_STATUS_REFUSED
						]),
						MiraklClient::ORDER_TYPE_PRODUCT
				);
        $this->assertCount(2, $transfers);

        $transfers = $this->paymentSplitService->getTransfersFromOrders(
						$this->miraklClient->listProductOrdersById([
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
								MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
						]),
						MiraklClient::ORDER_TYPE_PRODUCT
				);
				// SHIPPING is already pending
        $this->assertCount(1, $transfers);

        $transfers = $this->paymentSplitService->getTransfersFromOrders(
						$this->miraklClient->listProductOrdersById([
								MiraklMockedHttpClient::ORDER_STATUS_SHIPPING,
								MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
						]),
						MiraklClient::ORDER_TYPE_PRODUCT
				);
				// Both are already pending
        $this->assertCount(0, $transfers);

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(3, $transfers);
    }

    public function testUpdateTransfersFromOrders()
    {
				$orders = $this->miraklClient->listProductOrdersById([
						MiraklMockedHttpClient::ORDER_STATUS_STAGING,
						MiraklMockedHttpClient::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
						MiraklMockedHttpClient::ORDER_STATUS_REFUSED,
						MiraklMockedHttpClient::ORDER_STATUS_RECEIVED
				]);
        $transfers = $this->paymentSplitService->getTransfersFromOrders(
						$orders,
						MiraklClient::ORDER_TYPE_PRODUCT
				);
        $this->assertCount(4, $transfers);

				// Only STAGING and WAITING_DEBIT_PAYMENT are retriable
        $transfers = $this->paymentSplitService->getRetriableTransfers(MiraklClient::ORDER_TYPE_PRODUCT);
        $this->assertCount(2, $transfers);
				unset($orders[MiraklMockedHttpClient::ORDER_STATUS_REFUSED]);
				unset($orders[MiraklMockedHttpClient::ORDER_STATUS_RECEIVED]);

				// STAGING moved to SHIPPING \o/
				$id = MiraklMockedHttpClient::ORDER_STATUS_STAGING;
				$orders[$id]['order_state'] = 'SHIPPING';
				$orders[$id]['order_lines'][0]['order_line_state'] = 'SHIPPING';
				$orders[$id]['order_lines'][1]['order_line_state'] = 'SHIPPING';
        $transfers = $this->paymentSplitService
						->updateTransfersFromOrders($transfers, $orders);
        $this->assertCount(2, $transfers);

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(4, $transfers);
    }
}
