<?php

namespace App\Message;

class CancelPendingPaymentMessage
{
    /**
     * @var int
     */
    private $paymentMappingId;

    private ?int $attempt = null;

    public function __construct(int $paymentMappingId, ?int $attempt = null)
    {
        $this->paymentMappingId = $paymentMappingId;
        $this->attempt = $attempt;
    }

    public function getPaymentMappingId(): int
    {
        return $this->paymentMappingId;
    }

    public function getAttempt(): ?int
    {
        return $this->attempt;
    }
}
