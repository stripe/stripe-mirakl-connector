<?php

namespace App\Message;

use App\Entity\StripeCharge;

class CancelPendingPaymentMessage
{
    /**
     * @var int
     */
    private $stripePaymentId;

    /**
     * @var int
     */
    private $amount;

    public function __construct(int $stripePaymentId, int $amount)
    {
        $this->stripePaymentId = $stripePaymentId;
        $this->amount = $amount;
    }

    /**
     * @return int
     */
    public function getStripePaymentId(): int
    {
        return $this->stripePaymentId;
    }

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }
}
