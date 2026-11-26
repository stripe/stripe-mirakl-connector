<?php

namespace App\Exception;

/**
 * Thrown while processing a webhook when the failure is intentional and the message is
 * safe to return to the caller. Any other throwable is reported with a generic response.
 */
class WebhookProcessingException extends \RuntimeException
{
    public function __construct(string $message, private readonly int $httpStatus)
    {
        parent::__construct($message, $httpStatus);
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
