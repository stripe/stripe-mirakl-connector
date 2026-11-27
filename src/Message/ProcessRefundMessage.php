<?php

namespace App\Message;

class ProcessRefundMessage
{
    /**
     * @var string
     */
    private $stripeRefundId;

    private ?int $attempt = null;

    public function __construct(string $stripeRefundId, ?int $attempt = null)
    {
        $this->stripeRefundId = $stripeRefundId;
        $this->attempt = $attempt;
    }

    public function getStripeRefundId(): string
    {
        return $this->stripeRefundId;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }
}
