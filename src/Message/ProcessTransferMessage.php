<?php

namespace App\Message;

class ProcessTransferMessage
{
    /**
     * @var int
     */
    private $stripeTransferId;

    private ?int $attempt = null;

    public function __construct(int $stripeTransferId, ?int $attempt = null)
    {
        $this->stripeTransferId = $stripeTransferId;
        $this->attempt = $attempt;
    }

    public function getStripeTransferId(): int
    {
        return $this->stripeTransferId;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }
}
