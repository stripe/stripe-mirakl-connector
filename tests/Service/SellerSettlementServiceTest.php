<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Entity\StripePayout;
use App\Factory\StripePayoutFactory;
use App\Factory\StripeTransferFactory;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\SellerSettlementService;
use App\Tests\MiraklMockedHttpClient;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SellerSettlementServiceTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var SellerSettlementService
     */
    private $sellerSettlementService;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

        $this->miraklClient = $container->get('App\Service\MiraklClient');
        $this->stripeTransferRepository = $container->get('doctrine')->getRepository(StripeTransfer::class);
        $this->stripePayoutRepository = $container->get('doctrine')->getRepository(StripePayout::class);

        $stripeTransferFactory = new StripeTransferFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class),
            $container->get('doctrine')->getRepository(PaymentMapping::class),
            $container->get('doctrine')->getRepository(StripeRefund::class),
            $this->stripeTransferRepository,
            $this->miraklClient,
            $container->get('App\Service\StripeClient')
        );
        $stripeTransferFactory->setLogger(new NullLogger());

        $stripePayoutFactory = new StripePayoutFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class)
        );
        $stripePayoutFactory->setLogger(new NullLogger());

        $this->sellerSettlementService = new SellerSettlementService(
            $stripePayoutFactory,
            $stripeTransferFactory,
            $this->stripePayoutRepository,
            $this->stripeTransferRepository
        );
    }

    private function getTransfersFromRepository()
    {
        return $this->stripeTransferRepository->findBy([
            'type' => StripeTransfer::getInvoiceTypes()
        ]);
    }

    private function getPayoutsFromRepository()
    {
        return $this->stripePayoutRepository->findAll();
    }

    public function testGetRetriableTransfers()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $transfers = $this->sellerSettlementService->getTransfersFromInvoices($invoices);
        $this->assertEquals(3 + 9, count($transfers, COUNT_RECURSIVE));

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(9, $transfers);

        // Only 1 invoice is retriable (INVALID_SHOP)
        $transfers = $this->sellerSettlementService->getRetriableTransfers();
        $this->assertEquals(1 + 3, count($transfers, COUNT_RECURSIVE));
    }

    public function testGetRetriablePayouts()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $payouts = $this->sellerSettlementService->getPayoutsFromInvoices($invoices);
        $this->assertCount(3, $payouts);

        $payouts = $this->getPayoutsFromRepository();
        $this->assertCount(3, $payouts);

        // Only 1 invoice is retriable (INVALID_SHOP)
        $payouts = $this->sellerSettlementService->getRetriablePayouts();
        $this->assertCount(1, $payouts);
    }

    public function testGetTransfersFromInvoices()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $transfers = $this->sellerSettlementService->getTransfersFromInvoices($invoices);
        $this->assertEquals(3 + 9, count($transfers, COUNT_RECURSIVE));

        $transfers = $this->sellerSettlementService->getTransfersFromInvoices($invoices);
        // BASIC is already pending and AMOUNT_INVALID is aborted
        $this->assertEquals(1 + 3, count($transfers, COUNT_RECURSIVE));

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(9, $transfers);
    }

    public function testGetPayoutsFromInvoices()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $payouts = $this->sellerSettlementService->getPayoutsFromInvoices($invoices);
        $this->assertCount(3, $payouts);

        $payouts = $this->sellerSettlementService->getPayoutsFromInvoices($invoices);
        // BASIC is already pending and AMOUNT_INVALID is aborted
        $this->assertCount(1, $payouts);

        $payouts = $this->getPayoutsFromRepository();
        $this->assertCount(3, $payouts);
    }

    public function testUpdateTransfersFromInvoices()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $transfers = $this->sellerSettlementService->getTransfersFromInvoices($invoices);
        $this->assertEquals(3 + 9, count($transfers, COUNT_RECURSIVE));

        // Only INVALID_SHOP is retriable
        $transfers = $this->sellerSettlementService->getRetriableTransfers();
        $this->assertEquals(1 + 3, count($transfers, COUNT_RECURSIVE));

        // Shop is now ready \o/
        $id = MiraklMockedHttpClient::INVOICE_INVALID_SHOP;
        $invoices[$id]['shop_id'] = MiraklMockedHttpClient::SHOP_BASIC;
        $transfers = $this->sellerSettlementService
            ->updateTransfersFromInvoices($transfers, $invoices);
        $this->assertEquals(1 + 3, count($transfers, COUNT_RECURSIVE));

        $transfers = $this->getTransfersFromRepository();
        $this->assertCount(9, $transfers);
    }

    public function testUpdatePayoutsFromInvoices()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_1_VALID
        );
        $payouts = $this->sellerSettlementService->getPayoutsFromInvoices($invoices);
        $this->assertCount(3, $payouts);

        // Only INVALID_SHOP is retriable
        $payouts = $this->sellerSettlementService->getRetriablePayouts();
        $this->assertCount(1, $payouts);

        // Shop is now ready \o/
        $id = MiraklMockedHttpClient::INVOICE_INVALID_SHOP;
        $invoices[$id]['shop_id'] = MiraklMockedHttpClient::SHOP_BASIC;
        $payouts = $this->sellerSettlementService
            ->updatePayoutsFromInvoices($payouts, $invoices);
        $this->assertCount(1, $payouts);

        $payouts = $this->getPayoutsFromRepository();
        $this->assertCount(3, $payouts);
    }
}
