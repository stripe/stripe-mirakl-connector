<?php

namespace App\Message;

class CancelPendingPaymentMessage
{
    /**
     * @var int
     */
    private $paymentMappingId;

    public function __construct(int $paymentMappingId)
    {
        $this->paymentMappingId = $paymentMappingId;
    }

    public function getPaymentMappingId(): int
    {
        return $this->paymentMappingId;
    }
}
