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
    public const TEST_FROM_EMAIL = 'hello+from@test.com';
    public const TEST_TO_EMAIL = 'hello+to@test.com';
    public const TEST_SUBJECT = 'Hello world';

    private $emailFactory;
    private $mailer;
    private $symfonyMailerHandler;

    protected function setUp(): void
    {
        $this->emailFactory = new EmailFactory($this::TEST_FROM_EMAIL, $this::TEST_TO_EMAIL, $this::TEST_SUBJECT);
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
                    && $this::TEST_FROM_EMAIL === $email->getFrom()[0]->getAddress()
                    && $this::TEST_TO_EMAIL === $email->getTo()[0]->getAddress()
                    && $this::TEST_SUBJECT === $email->getSubject()
                    && str_contains($email->getHtmlBody(), 'new log message')
                    && str_contains($email->getHtmlBody(), 'Channel:')
                    && str_contains($email->getHtmlBody(), 'app')
                    && str_contains($email->getHtmlBody(), 'ERROR');
            }));

        $email = $this->symfonyMailerHandler->handleBatch([$logRecord]);


    }
}
