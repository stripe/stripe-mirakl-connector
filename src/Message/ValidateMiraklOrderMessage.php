<?php

namespace App\Message;

use App\Entity\PaymentMapping;

class ValidateMiraklOrderMessage
{
    /**
     * @var array
     */
    private $orders;

    /**
     * @var PaymentMapping[]
     */
    private $paymentMappings;

    public function __construct(array $orders, array $paymentMappings)
    {
        $this->orders = $orders;
        $this->paymentMappings = $paymentMappings;
    }

    /**
     * @return array
     */
    public function getOrders(): array
    {
        return $this->orders;
    }

    /**
     * @return PaymentMapping[]
     */
    public function getPaymentMappings(): array
    {
        return $this->paymentMappings;
    }
}
