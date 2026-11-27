<?php

namespace App\Message;

class CapturePendingPaymentMessage
{
    /**
     * @var int
     */
    private $paymentMappingId;

    /**
     * @var int
     */
    private $amount;

    private ?int $attempt = null;

    public function __construct(int $paymentMappingId, int $amount, ?int $attempt = null)
    {
        $this->paymentMappingId = $paymentMappingId;
        $this->amount = $amount;
        $this->attempt = $attempt;
    }

    public function getPaymentMappingId(): int
    {
        return $this->paymentMappingId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }
}
