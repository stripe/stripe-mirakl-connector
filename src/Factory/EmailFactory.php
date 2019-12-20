<?php

namespace App\Factory;

use Symfony\Component\Mime\Email;

/**
 * Helps create Email objects, lazily.
 */
class EmailFactory
{
    private $fromEmail;
    private $toEmail;
    private $subject;

    public function __construct(string $fromEmail, string $toEmail, string $subject)
    {
        $this->fromEmail = $fromEmail;
        $this->toEmail = $toEmail;
        $this->subject = $subject;
    }

    /**
     * Creates an Email template that will be used to send the log message.
     *
     * @return Email
     */
    public function createMessage(): Email
    {
        $message = new Email();
        $message->to($this->toEmail);
        $message->from($this->fromEmail);
        $message->subject($this->subject);

        return $message;
    }
}
