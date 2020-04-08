<?php

namespace App\Tests\MessageHandler;

use App\Entity\StripeTransfer;
use App\Entity\MiraklRefund;
use App\Handler\ProcessRefundHandler;
use App\Message\ProcessRefundMessage;
use App\Message\RefundFailedMessage;
use App\Repository\StripeTransferRepository;
use App\Repository\MiraklRefundRepository;
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
class ProcessRefundHandlerIntegrationTest extends WebTestCase
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
     * @var MiraklRefundRepository
     */
    private $miraklRefundRepository;

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
        $this->miraklRefundRepository = $container->get('doctrine')->getRepository(MiraklRefund::class);
        $this->messageBus = self::$container->get(MessageBusInterface::class);
        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->handler = new ProcessRefundHandler(
            $this->miraklClient,
            $this->stripeProxy,
            $this->stripeTransferRepository,
            $this->miraklRefundRepository,
            $this->messageBus,
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testProcessRefundHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage("1104");

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsCreated = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_CREATED,
        ]);
        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $this->assertEquals(4, count($miraklRefundsPending));
        $this->assertEquals(2, count($miraklRefundsCreated));
        $this->assertEquals('order_refunded_4', $miraklRefundsCreated[0]->getMiraklOrderId());
        $this->assertEquals('refund_4', $miraklRefundsCreated[0]->getStripeRefundId());
        $this->assertEquals('trr_4', $miraklRefundsCreated[0]->getStripeReversalId());
    }

    public function testProcessRefundHandlerWithRefundCreated()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage("1103");

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsCreated = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_CREATED,
        ]);
        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $this->assertEquals(5, count($miraklRefundsPending));
        $this->assertEquals(1, count($miraklRefundsCreated));
        $this->assertEquals('order_refunded_3', $miraklRefundsCreated[0]->getMiraklOrderId());
        $this->assertEquals('refund_3', $miraklRefundsCreated[0]->getStripeRefundId());
        $this->assertEquals('trr_3', $miraklRefundsCreated[0]->getStripeReversalId());
    }

    public function testProcessRefundHandlerWithStripeError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage('1107');

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $miraklRefundsFailed = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_FAILED,
        ]);

        $this->assertEquals(4, count($miraklRefundsPending));
        $this->assertEquals(1, count($miraklRefundsFailed));
        $this->assertEquals('order_refunded_5', $miraklRefundsFailed[0]->getMiraklOrderId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
        $this->assertInstanceOf(RefundFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'refund.failed',
            'payload' => [
                'internalId' => 6,
                'amount' => 6,
                'currency' => 'EUR',
                'miraklOrderId' => 'order_refunded_5',
                'miraklRefundId' => '1107',
                'stripeRefundId' => null,
                'stripeReversalId' => null,
                'status' => 'REFUND_FAILED',
                'failedReason' => '',
           ],
        ], $messageEnvelope->getMessage()->getContent());
    }

    public function testProcessRefundHandlerWithUnexistingTransfer()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage('1102');

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $miraklRefundsFailed = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_FAILED,
        ]);

        $this->assertEquals(4, count($miraklRefundsPending));
        $this->assertEquals(1, count($miraklRefundsFailed));
        $this->assertEquals('order_2', $miraklRefundsFailed[0]->getMiraklOrderId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
       $this->assertInstanceOf(RefundFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'refund.failed',
            'payload' => [
                'internalId' => 3,
                'amount' => 6,
                'currency' => 'EUR',
                'miraklOrderId' => 'order_2',
                'miraklRefundId' => '1102',
                'stripeRefundId' => null,
                'stripeReversalId' => null,
                'status' => 'REFUND_FAILED',
                'failedReason' => 'Mirakl refund id: 1102 and orderId: order_2 has no stripe transfer in connector',
            ],
        ], $messageEnvelope->getMessage()->getContent());

        $this->assertStringContainsString('has no stripe transfer in connector', $miraklRefundsFailed[0]->getFailedReason());
    }


    public function testProcessRefundHandlerWithUnexistingStripeRefundId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage('1105');

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $miraklRefundsFailed = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_FAILED,
        ]);

        $this->assertEquals(4, count($miraklRefundsPending));
        $this->assertEquals(1, count($miraklRefundsFailed));
        $this->assertEquals('order_refunded_5', $miraklRefundsFailed[0]->getMiraklOrderId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
       $this->assertInstanceOf(RefundFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'refund.failed',
            'payload' => [
                'internalId' => 4,
                'amount' => 6,
                'currency' => 'EUR',
                'miraklOrderId' => 'order_refunded_5',
                'miraklRefundId' => '1105',
                'stripeRefundId' => null,
                'stripeReversalId' => 'trr_5',
                'status' => 'REFUND_FAILED',
                'failedReason' => 'Mirakl refund id: 1105 and orderId: order_refunded_5 has no stripe refund id in connector',
            ],
        ], $messageEnvelope->getMessage()->getContent());

        $this->assertStringContainsString('has no stripe refund id in connector', $miraklRefundsFailed[0]->getFailedReason());
    }

    public function testProcessRefundHandlerWithUnexistingStripeReversalId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessRefundMessage('1106');

        $handler = $this->handler;
        $handler($message);

        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);
        $miraklRefundsFailed = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_FAILED,
        ]);

        $this->assertEquals(4, count($miraklRefundsPending));
        $this->assertEquals(1, count($miraklRefundsFailed));
        $this->assertEquals('order_refunded_5', $miraklRefundsFailed[0]->getMiraklOrderId());

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
       $this->assertInstanceOf(RefundFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'refund.failed',
            'payload' => [
                'internalId' => 5,
                'amount' => 6,
                'currency' => 'EUR',
                'miraklOrderId' => 'order_refunded_5',
                'miraklRefundId' => '1106',
                'stripeRefundId' => 'refund_6',
                'stripeReversalId' => null,
                'status' => 'REFUND_FAILED',
                'failedReason' => 'Mirakl refund id: 1106 and orderId: order_refunded_5 has no stripe reversal id in connector',
            ],
        ], $messageEnvelope->getMessage()->getContent());

        $this->assertStringContainsString('has no stripe reversal id in connector', $miraklRefundsFailed[0]->getFailedReason());
    }
}