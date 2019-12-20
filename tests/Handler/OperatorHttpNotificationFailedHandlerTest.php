<?php

namespace App\Tests\MessageHandler;

use App\Handler\OperatorHttpNotificationFailedHandler;
use App\Message\NotifiableMessageInterface;
use App\Message\NotificationFailedMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

/**
 * @group time-sensitive
 */
class OperatorHttpNotificationFailedHandlerTest extends TestCase
{
    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var OperatorHttpNotificationFailedHandler
     */
    private $handler;

    public const TECHNICAL_FROM = 'from@mail.com';
    public const TECHNICAL_TO = 'from@mail.com';
    public const NOTIFICATION_URL = 'https://mystore/api/notification';

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->handler = new OperatorHttpNotificationFailedHandler(
            $this->mailer,
            self::TECHNICAL_FROM,
            self::TECHNICAL_TO,
            self::NOTIFICATION_URL,
            10
        );

        $logger = new NullLogger();
        $this->handler->setLogger($logger);
    }

    public function testOperatorHttpNotificationFailedHandler()
    {
        ClockMock::register(OperatorHttpNotificationFailedHandler::class);

        $originalMessage = $this->createMock(NotifiableMessageInterface::class);

        $message = new NotificationFailedMessage(new \Exception('Error message'), $originalMessage);
        $test = $this;
        $this
            ->mailer
            ->expects($this->exactly(2))
            ->method('send')
            ->with($this->callback(function ($email) use ($test) {
                $test->assertInstanceOf(TemplatedEmail::class, $email);
                $test->assertEquals(self::TECHNICAL_FROM, $email->getFrom()[0]->getAddress());
                $test->assertEquals(self::TECHNICAL_TO, $email->getTo()[0]->getAddress());

                return true;
            }));

        $handler = $this->handler;
        $handler($message); // First notification is sent
        sleep(5 * 60); // Sleep 5 minutes
        $handler($message); // Second notification is skipped
        sleep(10 * 60); // Sleep 10 more minutes
        $handler($message); // Third notification is sent
    }
}
