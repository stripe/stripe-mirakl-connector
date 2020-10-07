<?php

namespace App\Message;

use App\Entity\StripePayment;

class CapturePendingPaymentMessage
{
    /**
     * @var StripePayment
     */
    private $stripePayment;

    /**
     * @var int
     */
    private $amount;

    public function __construct(StripePayment $stripePayment, int $amount)
    {
        $this->stripePayment = $stripePayment;
        $this->amount = $amount;
    }

    /**
     * @return StripePayment
     */
    public function getStripePayment(): StripePayment
    {
        return $this->stripePayment;
    }

    /**
     * @return int
     */
    public function getAmount(): int
    {
        return $this->amount;
    }
}
