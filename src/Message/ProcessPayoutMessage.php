<?php

namespace App\Message;

class ProcessPayoutMessage
{
    /**
     * @var int
     */
    private $stripePayoutId;

    private ?int $attempt = null;

    public function __construct(int $stripePayoutId, ?int $attempt = null)
    {
        $this->stripePayoutId = $stripePayoutId;
        $this->attempt = $attempt;
    }

    public function getStripePayoutId(): int
    {
        return $this->stripePayoutId;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }
}
