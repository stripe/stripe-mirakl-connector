<?php

namespace App\Tests\MessageHandler;

use App\Entity\StripePayout;
use App\Handler\ProcessPayoutHandler;
use App\Message\PayoutFailedMessage;
use App\Message\ProcessPayoutMessage;
use App\Repository\StripePayoutRepository;
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
class ProcessPayoutsHandlerIntegrationTest extends WebTestCase
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
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

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
        $this->stripePayoutRepository = $container->get('doctrine')->getRepository(StripePayout::class);
        $this->messageBus = self::$container->get(MessageBusInterface::class);
        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->handler = new ProcessPayoutHandler(
            $this->miraklClient,
            $this->stripeProxy,
            $this->stripePayoutRepository,
            $this->messageBus
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testProcessPayoutHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessPayoutMessage(2);

        $handler = $this->handler;
        $handler($message);

        $stripePayoutsCreated = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_CREATED,
        ]);

        $this->assertEquals(2, count($stripePayoutsCreated));
    }

    public function testProcessPayoutHandlerWithUnknownId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessPayoutMessage(9999);

        $handler = $this->handler;
        $handler($message);

        $stripePayoutsCreated = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_CREATED,
        ]);

        // No payout was created, no error
        $this->assertEquals(1, count($stripePayoutsCreated));
    }

    public function testProcessPayoutHandlerWithStripeError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessPayoutMessage(3);

        $handler = $this->handler;
        $handler($message);

        $stripePayoutsFailed = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_FAILED,
        ]);

        $this->assertEquals(1, count($stripePayoutsFailed));

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
        $this->assertInstanceOf(PayoutFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'payout.failed',
            'payload' => [
                'internalId' => 3,
                'miraklInvoiceId' => 3,
                'amount' => 2000,
                'currency' => 'eur',
                'stripePayoutId' => null,
                'status' => 'PAYOUT_FAILED',
                'failedReason' => '',
            ],
        ], $messageEnvelope->getMessage()->getContent());
    }
    public function testProcessPayoutHandlerWithDisabledPayout()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $message = new ProcessPayoutMessage(4);

        $handler = $this->handler;
        $handler($message);

        $stripePayoutsFailed = $this->stripePayoutRepository->findBy([
            'status' => StripePayout::PAYOUT_FAILED,
        ]);

        $this->assertEquals(1, count($stripePayoutsFailed));

        $this->assertEquals(1, $this->httpNotificationReceiver->getMessageCount());
        $messageEnvelope = $this->httpNotificationReceiver->get()[0];
        $this->assertInstanceOf(PayoutFailedMessage::class, $messageEnvelope->getMessage());
        $this->assertEquals([
            'type' => 'payout.failed',
            'payload' => [
                'internalId' => 4,
                'miraklInvoiceId' => 999,
                'amount' => 6000,
                'currency' => 'eur',
                'stripePayoutId' => null,
                'status' => 'PAYOUT_FAILED',
                'failedReason' => 'Unknown account, or payout is not enabled on this Stripe account',
            ],
        ], $messageEnvelope->getMessage()->getContent());
    }
}
