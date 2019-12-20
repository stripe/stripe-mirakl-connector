<?php

namespace App\Message;

class NotificationFailedMessage
{
    /**
     * @var NotifiableMessageInterface
     */
    private $originalMessage;

    /**
     * @var \Throwable
     */
    private $failedException;

    public function __construct(\Throwable $failedException, NotifiableMessageInterface $originalMessage)
    {
        $this->failedException = $failedException;
        $this->originalMessage = $originalMessage;
    }

    public function getFailedException(): \Throwable
    {
        return $this->failedException;
    }

    public function getOriginalMessage(): NotifiableMessageInterface
    {
        return $this->originalMessage;
    }
}
