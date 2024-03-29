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

    public function __construct(int $paymentMappingId, int $amount)
    {
        $this->paymentMappingId = $paymentMappingId;
        $this->amount = $amount;
    }

    public function getPaymentMappingId(): int
    {
        return $this->paymentMappingId;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }
}
