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
            $this->messageBus
        );

        $this->handler->setLogger(new NullLogger());
    }

    public function testProcessTransferHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $countPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $countFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 1);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $newCountPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $newCountFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $this->assertEquals($countCreated + 1, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed, $newCountFailed);
    }

    public function testProcessTransferHandlerWithStripeError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $countPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $countFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 2);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $newCountPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $newCountFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed + 1, $newCountFailed);

        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
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
                ]
            ]
        ));
    }

    public function testProcessTransferHandlerWithUnexistingMapping()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $countPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $countFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 3);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $newCountPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $newCountFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed + 1, $newCountFailed);

        $this->assertTrue($this->hasNotification(
            TransferFailedMessage::class,
            [
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
                ]
            ]
        ));
    }

    public function testProcessTransferHandlerWithUnexistingTransferId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $countPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $countFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, 9999);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripeTransfer::TRANSFER_CREATED);
        $newCountPending = $this->countByStatus(StripeTransfer::TRANSFER_PENDING);
        $newCountFailed = $this->countByStatus(StripeTransfer::TRANSFER_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending, $newCountPending);
        $this->assertEquals($countFailed, $newCountFailed);
    }

    private function countByStatus($status): int
    {
        return count($this->stripeTransferRepository->findBy([
            'status' => $status,
        ]));
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
