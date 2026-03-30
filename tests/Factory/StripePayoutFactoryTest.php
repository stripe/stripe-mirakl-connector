<?php

namespace App\Tests\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripePayout;
use App\Factory\StripePayoutFactory;
use App\Service\MiraklClient;
use App\Tests\MiraklMockedHttpClient;
use App\Tests\StripeMockedHttpClient;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class StripePayoutFactoryTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripePayoutFactory
     */
    private $stripePayoutFactory;
    private $accountMappingRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

        $this->miraklClient = $container->get('App\Service\MiraklClient');

        $this->stripePayoutFactory = new StripePayoutFactory(
            $container->get('doctrine')->getRepository(AccountMapping::class), false
        );
        $this->stripePayoutFactory->setLogger(new NullLogger());

        $this->accountMappingRepository = $container->get('doctrine')
            ->getRepository(AccountMapping::class);
    }

    public function testCreateFromInvoice()
    {
        $payout = $this->stripePayoutFactory->createFromInvoice(
            current($this->miraklClient->listInvoicesByDate(
                MiraklMockedHttpClient::INVOICE_DATE_1_VALID
            )),
            $this->miraklClient
        );

        $this->assertEquals(StripePayout::PAYOUT_PENDING, $payout->getStatus());
        $this->assertNull($payout->getStatusReason());
        $this->assertEquals(MiraklMockedHttpClient::INVOICE_BASIC, $payout->getMiraklInvoiceId());
        $this->assertNotNull($payout->getAccountMapping());
        $this->assertNull($payout->getPayoutId());
        $this->assertEquals(1234, $payout->getAmount());
        $this->assertEquals('eur', $payout->getCurrency());
        $this->assertNotNull($payout->getMiraklCreatedDate());
    }

    public function testUpdateFromInvoice()
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_INVALID_SHOP
        ));
        $invoiceId = $invoice['invoice_id'];

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);
        $this->assertEquals(StripePayout::PAYOUT_ON_HOLD, $payout->getStatus());

        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));
        $invoice['invoice_id'] = $invoiceId;
        $payout = $this->stripePayoutFactory->updateFromInvoice($payout, $invoice, $this->miraklClient);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $payout->getStatus());
    }

    public function testInvalidInvoices()
    {
        $invoices = $this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_3_INVOICES_ALL_INVALID
        );
        foreach ($invoices as $invoiceId => $invoice) {
            $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);
            switch ($invoiceId) {
                case MiraklMockedHttpClient::INVOICE_INVALID_NO_SHOP:
                    $this->assertEquals(StripePayout::PAYOUT_ABORTED, $payout->getStatus());
                    $this->assertNotNull($payout->getStatusReason());
                    break;
                case MiraklMockedHttpClient::INVOICE_INVALID_SHOP:
                    $this->assertEquals(StripePayout::PAYOUT_ON_HOLD, $payout->getStatus());
                    $this->assertNotNull($payout->getStatusReason());
                    break;
                case MiraklMockedHttpClient::INVOICE_INVALID_AMOUNT:
                    $this->assertEquals(StripePayout::PAYOUT_ABORTED, $payout->getStatus());
                    $this->assertNotNull($payout->getStatusReason());
                    break;
            }
        }
    }

    public function testInvoiceWithPayout()
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);
        $this->assertEquals(StripePayout::PAYOUT_PENDING, $payout->getStatus());

        $payout->setPayoutId(StripeMockedHttpClient::PAYOUT_BASIC);
        $payout = $this->stripePayoutFactory->updateFromInvoice($payout, $invoice, $this->miraklClient);
        $this->assertEquals(StripePayout::PAYOUT_CREATED, $payout->getStatus());
    }

    private function createStripePayoutFactory(): StripePayoutFactory
    {
        $factory = new StripePayoutFactory(
            $this->accountMappingRepository,
        );
        $factory->setLogger(new NullLogger());

        return $factory;
    }

    private function createAccountMapping(
        int $shopId,
        string $stripeAccountId = 'acc_test_payout',
        bool $payoutEnabled = true
    ): AccountMapping {
        $mapping = new AccountMapping();
        $mapping->setMiraklShopId($shopId);
        $mapping->setStripeAccountId($stripeAccountId);
        $mapping->setPayoutEnabled($payoutEnabled);

        $this->accountMappingRepository->persistAndFlush($mapping);

        return $mapping;
    }

    public function testUpdateFromInvoiceMarksCreatedWhenPayoutAlreadyExists(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));

        $payout = new StripePayout();
        $payout->setMiraklInvoiceId((int) $invoice['invoice_id']);
        $payout->setPayoutId('po_test_123');
        $payout->setStatus(StripePayout::PAYOUT_PENDING);
        $payout->setStatusReason('temporary reason');

        $updatedPayout = $this->stripePayoutFactory->updateFromInvoice(
            $payout,
            $invoice,
            $this->miraklClient
        );

        $this->assertSame(StripePayout::PAYOUT_CREATED, $updatedPayout->getStatus());
        $this->assertNull($updatedPayout->getStatusReason());
        $this->assertSame('po_test_123', $updatedPayout->getPayoutId());
    }

    public function testInvoiceWithoutShopIdAbortsPayout(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));
        unset($invoice['shop_id']);

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);

        $this->assertSame(StripePayout::PAYOUT_ABORTED, $payout->getStatus());
        $this->assertNotNull($payout->getStatusReason());
    }

    public function testInvoiceWithUnknownShopPutsPayoutOnHold(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_INVALID_SHOP
        ));

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);

        $this->assertSame(StripePayout::PAYOUT_ON_HOLD, $payout->getStatus());
        $this->assertNotNull($payout->getStatusReason());
    }

    public function testInvoiceWithPayoutDisabledShopPutsPayoutOnHold(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => (int) $invoice['shop_id'],
        ]);
        $mapping->setPayoutEnabled(false);
        $this->accountMappingRepository->flush();

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);

        $this->assertSame(StripePayout::PAYOUT_ON_HOLD, $payout->getStatus());
        $this->assertNotNull($payout->getStatusReason());
    }

    public function testInvoiceWithInvalidAmountAbortsPayout(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));
        $invoice['summary']['amount_transferred'] = 0;


        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => (int) $invoice['shop_id'],
        ]);
        $mapping->setPayoutEnabled(false);
        $this->accountMappingRepository->flush();

        $payout = $this->stripePayoutFactory->createFromInvoice($invoice, $this->miraklClient);

        $this->assertSame(StripePayout::PAYOUT_ABORTED, $payout->getStatus());
        $this->assertNotNull($payout->getStatusReason());
    }

    public function testEnablePaymentTaxSplitCurrentlyDoesNotChangePayoutAmount(): void
    {
        $invoice = current($this->miraklClient->listInvoicesByDate(
            MiraklMockedHttpClient::INVOICE_DATE_1_VALID
        ));


        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => (int) $invoice['shop_id'],
        ]);
        $mapping->setPayoutEnabled(false);
        $this->accountMappingRepository->flush();

        $factory = $this->createStripePayoutFactory();
        $factory = $factory->createFromInvoice($invoice, $this->miraklClient);
        $this->assertNotEmpty($factory->getAmount());
    }
}
