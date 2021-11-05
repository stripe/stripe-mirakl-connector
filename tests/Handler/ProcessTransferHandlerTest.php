<?php

namespace App\Tests\MessageHandler;

use App\Entity\AccountMapping;
use App\Entity\StripeTransfer;
use App\Handler\ProcessTransferHandler;
use App\Message\ProcessTransferMessage;
use App\Message\TransferFailedMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessTransferHandlerTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->accountMappingRepository = $container->get('doctrine')->getRepository(AccountMapping::class);
        $this->stripeTransferRepository = $container->get('doctrine')->getRepository(StripeTransfer::class);

        $this->handler = new ProcessTransferHandler(
            $container->get('App\Service\StripeClient'),
            $this->stripeTransferRepository,
            self::$container->get(MessageBusInterface::class)
        );
        $this->handler->setLogger(new NullLogger());
    }

    private function executeHandler($stripeTransferId)
    {
        ($this->handler)(new ProcessTransferMessage($stripeTransferId));
    }

    private function mockTransfer($type, $transactionId = null, $accountId = StripeMock::ACCOUNT_BASIC)
    {
        $accountMapping = $this->accountMappingRepository->findOneBy([
            'stripeAccountId' => $accountId
        ]);

        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setAccountMapping($accountMapping);
        $transfer->setMiraklId('random');
        $transfer->setAmount(1234);
        $transfer->setCurrency('eur');
        $transfer->setTransactionId($transactionId);
        $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);

        $this->stripeTransferRepository->persistAndFlush($transfer);

        return $transfer;
    }

    public function testProductOrderTransfer()
    {
        $transfer = $this->mockTransfer(StripeTransfer::TRANSFER_PRODUCT_ORDER);
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testProductOrderTransferWithTransactionId()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_PRODUCT_ORDER,
            StripeMock::CHARGE_BASIC
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testProductOrderTransferWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_PRODUCT_ORDER,
            StripeMock::CHARGE_WITH_TRANSFER
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_PRODUCT_ORDER,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_BASIC,
                    'miraklShopId' => 1,
                    'transferId' => null,
                    'transactionId' => StripeMock::CHARGE_WITH_TRANSFER,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfer with source_transaction and charge has no more funds left.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    public function testServiceOrderTransfer()
    {
        $transfer = $this->mockTransfer(StripeTransfer::TRANSFER_SERVICE_ORDER);
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testServiceOrderTransferWithTransactionId()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_SERVICE_ORDER,
            StripeMock::CHARGE_BASIC
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testServiceOrderTransferWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_SERVICE_ORDER,
            StripeMock::CHARGE_WITH_TRANSFER
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_SERVICE_ORDER,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_BASIC,
                    'miraklShopId' => 1,
                    'transferId' => null,
                    'transactionId' => StripeMock::CHARGE_WITH_TRANSFER,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfer with source_transaction and charge has no more funds left.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    public function testExtraCreditsTransfer()
    {
        $transfer = $this->mockTransfer(StripeTransfer::TRANSFER_EXTRA_CREDITS);
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testExtraCreditsTransferWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_EXTRA_CREDITS,
            null,
            StripeMock::ACCOUNT_PAYIN_DISABLED
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_EXTRA_CREDITS,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_PAYIN_DISABLED,
                    'miraklShopId' => 2,
                    'transferId' => null,
                    'transactionId' => null,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfers disabled.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    public function testSubscriptionTransfer()
    {
        $transfer = $this->mockTransfer(StripeTransfer::TRANSFER_SUBSCRIPTION);
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testSubscriptionWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_SUBSCRIPTION,
            null,
            StripeMock::ACCOUNT_PAYIN_DISABLED
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_SUBSCRIPTION,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_PAYIN_DISABLED,
                    'miraklShopId' => 2,
                    'transferId' => null,
                    'transactionId' => null,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfers disabled.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    public function testExtraInvoicesTransfer()
    {
        $transfer = $this->mockTransfer(StripeTransfer::TRANSFER_EXTRA_INVOICES);
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_BASIC, $transfer->getTransferId());
    }

    public function testExtraInvoicesWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_EXTRA_INVOICES,
            null,
            StripeMock::ACCOUNT_PAYIN_DISABLED
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_EXTRA_INVOICES,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_PAYIN_DISABLED,
                    'miraklShopId' => 2,
                    'transferId' => null,
                    'transactionId' => null,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfers disabled.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    public function testRefundTransfer()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_REFUND,
            StripeMock::TRANSFER_BASIC
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $transfer->getStatus());
        $this->assertEquals(StripeMock::TRANSFER_REVERSAL_BASIC, $transfer->getTransferId());
    }

    public function testRefundWithApiError()
    {
        $transfer = $this->mockTransfer(
            StripeTransfer::TRANSFER_REFUND,
            StripeMock::TRANSFER_WITH_REVERSAL
        );
        $this->executeHandler($transfer->getId());

        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $transfer->getId()
        ]);

        $this->assertEquals(StripeTransfer::TRANSFER_FAILED, $transfer->getStatus());
        $this->assertNotNull($transfer->getStatusReason());
        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
                'type' => 'transfer.failed',
                'payload' => [
                    'internalId' => $transfer->getId(),
                    'type' => StripeTransfer::TRANSFER_REFUND,
                    'miraklId' => 'random',
                    'stripeAccountId' => StripeMock::ACCOUNT_BASIC,
                    'miraklShopId' => 1,
                    'transferId' => null,
                    'transactionId' => StripeMock::TRANSFER_WITH_REVERSAL,
                    'amount' => 1234,
                    'status' => 'TRANSFER_FAILED',
                    'failedReason' => 'Transfer already reversed.',
                    'currency' => 'eur',
                ]
            ]
        ));
    }

    private function hasNotification($class, $content): bool
    {
        foreach ($this->httpNotificationReceiver->get() as $messageEnvelope) {
            $message = $messageEnvelope->getMessage();
            if ($message instanceof $class && $message->getContent() == $content) {
                return true;
            }
        }

        return false;
    }
}
