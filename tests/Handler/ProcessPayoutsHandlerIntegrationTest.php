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

        $this->handler->setLogger(new NullLogger());
    }

    public function testProcessPayoutHandler()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $countPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $countFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $message = new ProcessPayoutMessage(2);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $newCountPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $newCountFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $this->assertEquals($countCreated + 1, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed, $newCountFailed);
    }

    public function testProcessPayoutHandlerWithUnknownId()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $countPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $countFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $message = new ProcessPayoutMessage(9999);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $newCountPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $newCountFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending, $newCountPending);
        $this->assertEquals($countFailed, $newCountFailed);
    }

    public function testProcessPayoutHandlerWithStripeError()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $countPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $countFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $message = new ProcessPayoutMessage(3);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $newCountPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $newCountFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed + 1, $newCountFailed);

        $this->assertTrue($this->hasNotification(
            PayoutFailedMessage::class,
            [
                'type' => 'payout.failed',
                'payload' => [
                    'internalId' => 3,
                    'miraklInvoiceId' => 3,
                    'amount' => 1234,
                    'currency' => 'eur',
                    'stripePayoutId' => null,
                    'status' => 'PAYOUT_FAILED',
                    'failedReason' => '',
                ]
            ]
        ));
    }

    public function testProcessPayoutHandlerWithDisabledPayout()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['command' => $this->command->getName()]);

        $countCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $countPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $countFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $message = new ProcessPayoutMessage(4);
        $handler = $this->handler;
        $handler($message);

        $newCountCreated = $this->countByStatus(StripePayout::PAYOUT_CREATED);
        $newCountPending = $this->countByStatus(StripePayout::PAYOUT_PENDING);
        $newCountFailed = $this->countByStatus(StripePayout::PAYOUT_FAILED);

        $this->assertEquals($countCreated, $newCountCreated);
        $this->assertEquals($countPending - 1, $newCountPending);
        $this->assertEquals($countFailed + 1, $newCountFailed);

        $this->assertTrue($this->hasNotification(
            PayoutFailedMessage::class,
            [
                'type' => 'payout.failed',
                'payload' => [
                    'internalId' => 4,
                    'miraklInvoiceId' => 999,
                    'amount' => 1234,
                    'currency' => 'eur',
                    'stripePayoutId' => null,
                    'status' => 'PAYOUT_FAILED',
                    'failedReason' => 'Unknown account, or payout is not enabled on this Stripe account',
                ]
            ]
        ));
    }

    private function countByStatus($status): int
    {
        return count($this->stripePayoutRepository->findBy([
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
