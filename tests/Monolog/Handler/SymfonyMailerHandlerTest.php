<?php

namespace App\Tests\Controller;

use App\Factory\EmailFactory;
use App\Monolog\Handler\SymfonyMailerHandler;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Monolog\Logger;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class SymfonyMailerHandlerTest extends TestCase
{
    private const TEST_FROM_EMAIL = 'hello+from@test.com';
    private const TEST_TO_EMAIL = 'hello+to@test.com';
    private const TEST_SUBJECT = 'Hello world';

    private $emailFactory;
    private $mailer;
    private $symfonyMailerHandler;

    protected function setUp(): void
    {
        $this->emailFactory = new EmailFactory(self::TEST_FROM_EMAIL, self::TEST_TO_EMAIL, self::TEST_SUBJECT);
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->symfonyMailerHandler = new SymfonyMailerHandler($this->mailer, $this->emailFactory);
    }

    public function testCreateMessage()
    {
        $logRecord = [
            'message' => 'new log message',
            'context' => [],
            'level' => Logger::ERROR,
            'level_name' => 'ERROR',
            'channel' => 'app',
            'datetime' => new DateTimeImmutable(),
            'extra' => [],
        ];

        $expectedMessage = new Email();
        $this
            ->mailer
            ->expects($this->once())
            ->method('send')
            ->with($this->callback(function ($email) {
                return $email instanceof Email
                    && self::TEST_FROM_EMAIL === $email->getFrom()[0]->getAddress()
                    && self::TEST_TO_EMAIL === $email->getTo()[0]->getAddress()
                    && self::TEST_SUBJECT === $email->getSubject()
                    && false !== strpos($email->getHtmlBody(), 'app.ERROR: new log message');
            }));

        $email = $this->symfonyMailerHandler->handleBatch([$logRecord]);
    }
}
