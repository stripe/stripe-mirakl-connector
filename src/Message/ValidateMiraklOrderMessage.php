<?php

namespace App\Message;

class ValidateMiraklOrderMessage
{
    /**
     * @var array
     */
    private $orders;

    public function __construct(array $orders)
    {
        $this->orders = $orders;
    }

    public function geOrders(): array
    {
        return $this->orders;
    }
}
