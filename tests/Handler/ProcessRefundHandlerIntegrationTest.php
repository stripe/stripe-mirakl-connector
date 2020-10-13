<?php

namespace App\Tests\Handler;

use App\Entity\StripeTransfer;
use App\Entity\StripeRefund;
use App\Handler\ProcessRefundHandler;
use App\Message\ProcessRefundMessage;
use App\Message\RefundFailedMessage;
use App\Repository\StripeTransferRepository;
use App\Repository\StripeRefundRepository;
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
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

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
        $this->stripeRefundRepository = $container->get('doctrine')->getRepository(StripeRefund::class);
        $this->messageBus = self::$container->get(MessageBusInterface::class);
        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->handler = new ProcessRefundHandler(
            $this->miraklClient,
            $this->stripeProxy,
            $this->stripeTransferRepository,
            $this->stripeRefundRepository,
            $this->messageBus
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testProcessRefundHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1104');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsCreated = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_CREATED,
        ]);
        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsCreated);
        $this->assertEquals('order_refunded_4', $stripeRefundsCreated[0]->getMiraklOrderId());
        $this->assertEquals('refund_4', $stripeRefundsCreated[0]->getStripeRefundId());
        $this->assertEquals('trr_4', $stripeRefundsCreated[0]->getStripeReversalId());
        $this->assertNotNull($stripeRefundsCreated[0]->getMiraklValidationTime());
    }

    public function testProcessRefundHandlerWithRefundCreated()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1103');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsCreated = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_CREATED,
        ]);
        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $this->assertCount(8, $stripeRefundsPending);
        $this->assertCount(1, $stripeRefundsCreated);
        $this->assertEquals('order_refunded_3', $stripeRefundsCreated[0]->getMiraklOrderId());
        $this->assertEquals('refund_3', $stripeRefundsCreated[0]->getStripeRefundId());
        $this->assertEquals('trr_3', $stripeRefundsCreated[0]->getStripeReversalId());
    }

    public function testProcessRefundHandlerWithRefundStripeApiError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1108');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => 7,
                    'amount' => 6,
                    'currency' => 'EUR',
                    'miraklOrderId' => 'order_refunded_5',
                    'miraklRefundId' => '1108',
                    'stripeRefundId' => null,
                    'stripeReversalId' => 'trr_8',
                    'status' => 'REFUND_FAILED',
                    'failedReason' => 'Could not create refund in stripe: ',
                ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithReversalStripeApiError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1109');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => 8,
                    'amount' => 6,
                    'currency' => 'EUR',
                    'miraklOrderId' => 'order_refunded_5',
                    'miraklRefundId' => '1109',
                    'stripeRefundId' => 'refund_9',
                    'stripeReversalId' => null,
                    'status' => 'REFUND_FAILED',
                    'failedReason' => 'Could not create reversal in stripe: ',
                ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithRefundValidationMiraklApiError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1110');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => 9,
                    'amount' => 6,
                    'currency' => 'EUR',
                    'miraklOrderId' => 'order_refunded_5',
                    'miraklRefundId' => '1110',
                    'stripeRefundId' => 'refund_10',
                    'stripeReversalId' => 'trr_10',
                    'status' => 'REFUND_FAILED',
                    'failedReason' => 'Mirakl refund id: 1110 and orderId: order_refunded_5 failed to be validated in mirakl. code: 400 message: Generated Error',
               ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithUnexistingTransfer()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1102');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_2', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
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
                ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithUnexistingStripeRefundId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1105');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
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
                ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithUnexistingStripeReversalId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1106');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
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
                ]
            ]
        ));
    }

    public function testProcessRefundHandlerWithUnexistingRefund()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('9999');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(8, $stripeRefundsPending);
        $this->assertCount(1, $stripeRefundsFailed);
    }

    public function testProcessRefundHandlerWithUnexistingMiraklValidationTime()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([ 'command' => $this->command->getName() ]);

        $message = new ProcessRefundMessage('1107');
        $handler = $this->handler;
        $handler($message);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);
        $stripeRefundsFailed = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_FAILED,
        ]);

        $this->assertCount(7, $stripeRefundsPending);
        $this->assertCount(2, $stripeRefundsFailed);
        $this->assertEquals('order_refunded_5', $stripeRefundsFailed[0]->getMiraklOrderId());

        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => 6,
                    'amount' => 6,
                    'currency' => 'EUR',
                    'miraklOrderId' => 'order_refunded_5',
                    'miraklRefundId' => '1107',
                    'stripeRefundId' => 'refund_7',
                    'stripeReversalId' => 'trr_7',
                    'status' => 'REFUND_FAILED',
                    'failedReason' => 'Mirakl refund id: 1107 and orderId: order_refunded_5 has no mirakl validation time',
                ]
            ]
        ));
    }

    private function hasNotification($class, $content)
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
