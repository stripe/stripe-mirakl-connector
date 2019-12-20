<?php

declare(strict_types=1);

namespace App\Monolog\Handler;

use App\Factory\EmailFactory;
use Monolog\Formatter\FormatterInterface;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\MailHandler;
use Monolog\Logger;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * SymfonyMailerHandler uses Symfony Mailer component to send the emails.
 */
class SymfonyMailerHandler extends MailHandler
{
    protected $mailer;
    private $messageFactory;

    /**
     * @param MailerInterface $mailer         The mailer to use
     * @param EmailFactory    $messageFactory
     * @param int             $level          The minimum logging level at which this handler will be triggered
     * @param bool            $bubble         Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(MailerInterface $mailer, EmailFactory $messageFactory, $level = Logger::ERROR, bool $bubble = true)
    {
        parent::__construct($level, $bubble);
        $this->mailer = $mailer;
        $this->messageFactory = $messageFactory;
    }

    /**
     * {@inheritdoc}
     */
    protected function send($content, array $records): void
    {
        $this->mailer->send($this->buildMessage($content, $records));
    }

    /**
     * Gets the formatter for the Email subject.
     *
     * @param string $format The format of the subject
     */
    protected function getSubjectFormatter(string $format): FormatterInterface
    {
        return new LineFormatter($format);
    }

    /**
     * Creates instance of Email to be sent.
     *
     * @param string $content formatted email body to be sent
     * @param array  $records Log records that formed the content
     *
     * @return Email
     */
    protected function buildMessage($content, array $records): Email
    {
        $message = $this->messageFactory->createMessage();
        if ($records) {
            $subjectFormatter = $this->getSubjectFormatter($message->getSubject() ?? '');
            $message->subject($subjectFormatter->format($this->getHighestRecord($records)));
        }
        $message->html($content);
        $message->date(new \DateTime());

        return $message;
    }
}
