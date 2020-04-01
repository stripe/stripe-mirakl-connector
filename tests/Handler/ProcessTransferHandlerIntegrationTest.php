<?php

namespace App\Tests\MessageHandler;

use App\Entity\StripeTransfer;
use App\Handler\ProcessTransferHandler;
use App\Message\ProcessTransferMessage;
use App\Message\TransferFailedMessage;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @group integration
 */
class ProcessTransferHandlerIntegrationTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();

        $application = new Application($kernel);
        $this->command = $application->find('messenger:setup-transports');

        $this->miraklClient = $container->get('App\Utils\MiraklClient');
        $this->stripeProxy = $container->get('App\Utils\StripeProxy');
        $this->stripeTransferRepository = $container->get('doctrine')->getRepository(StripeTransfer::class);
        $this->messageBus = self::$container->get(MessageBusInterface::class);
        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->handler = new ProcessTransferHandler(
            $this->miraklClient,
            $this->stripeProxy,
            $this->stripeTransferRepository,
            $this->messageBus,
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testProcessTransferHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 1);

        $handler = $this->handler;
        $handler($message);

        $stripeTransfersCreated = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_CREATED,
        ]);
        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING,
        ]);
        $this->assertEquals(2, count($stripeTransfersPending));
        $this->assertEquals(3, count($stripeTransfersCreated));
        $this->assertEquals('order_1', $stripeTransfersCreated[0]->getMiraklId());
    }

    public function testProcessTransferHandlerWithStripeError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 2);

        $handler = $this->handler;
        $handler($message);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING,
        ]);
        $stripeTransfersFailed = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_FAILED,
        ]);

        $this->assertEquals(2, count($stripeTransfersPending));
        $this->assertEquals(1, count($stripeTransfersFailed));
        $this->assertEquals('order_2', $stripeTransfersFailed[0]->getMiraklId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
        $this->assertInstanceOf(TransferFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'transfer.failed',
            'payload' => [
                'internalId' => 2,
                'type' => StripeTransfer::TRANSFER_ORDER,
                'miraklId' => 'order_2',
                'stripeAccountId' => 'acct_2',
                'miraklShopId' => 2,
                'transferId' => null,
                'transactionId' => 'py_transaction_2',
                'amount' => 24,
                'status' => 'TRANSFER_FAILED',
                'failedReason' => '',
                'currency' => 'EUR',
            ],
        ], $messageEnvelope->getMessage()->getContent());
    }

    public function testProcessTransferHandlerWithUnexistingMapping()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 3);

        $handler = $this->handler;
        $handler($message);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING,
        ]);
        $stripeTransfersFailed = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_FAILED,
        ]);

        $this->assertEquals(2, count($stripeTransfersPending));
        $this->assertEquals(1, count($stripeTransfersFailed));
        $this->assertEquals('order_3', $stripeTransfersFailed[0]->getMiraklId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
        $this->assertInstanceOf(TransferFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'transfer.failed',
            'payload' => [
                'internalId' => 3,
                'type' => StripeTransfer::TRANSFER_ORDER,
                'miraklId' => 'order_3',
                'stripeAccountId' => null,
                'miraklShopId' => null,
                'transferId' => null,
                'transactionId' => 'ch_transaction_3',
                'amount' => 24,
                'status' => 'TRANSFER_FAILED',
                'failedReason' => 'Stripe transfer 3 has no associated Mirakl-Stripe mapping',
                'currency' => 'EUR',
            ],
        ], $messageEnvelope->getMessage()->getContent());

        $this->assertStringContainsString('has no associated Mirakl-Stripe mapping', $stripeTransfersFailed[0]->getFailedReason());
    }
}
