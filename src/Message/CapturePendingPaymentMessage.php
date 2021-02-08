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

    /**
     * @return int
     */
    public function getPaymentMappingId(): int
    {
        return $this->paymentMappingId;
    }

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }
}
