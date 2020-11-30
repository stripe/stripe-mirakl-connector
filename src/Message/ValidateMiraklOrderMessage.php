<?php

namespace App\Message;

use App\Entity\StripeCharge;

class ValidateMiraklOrderMessage
{
    /**
     * @var array
     */
    private $orders;

    /**
     * @var StripeCharge[]
     */
    private $stripePayments;

    public function __construct(array $orders, array $stripePayments)
    {
        $this->orders = $orders;
        $this->stripePayments = $stripePayments;
    }

    /**
     * @return array
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * @return StripeCharge[]
     */
    public function getStripePayments(): array
    {
        return $this->stripePayments;
    }
}
