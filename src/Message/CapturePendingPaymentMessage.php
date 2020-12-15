<?php

namespace App\Message;

class CapturePendingPaymentMessage
{
    /**
     * @var int
     */
    private $stripeChargeId;

    /**
     * @var int
     */
    private $amount;

    public function __construct(int $stripeChargeId, int $amount)
    {
        $this->stripeChargeId = $stripeChargeId;
        $this->amount = $amount;
    }

    /**
     * @return int
     */
    public function getstripeChargeId(): int
    {
        return $this->stripeChargeId;
    }

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }
}
