<?php

namespace App\Entity;

class MiraklServicePendingRefund extends MiraklPendingRefund
{
    public function getId(): string
    {
        return $this->order['id'];
    }

    public function getOrderId(): string
    {
        return $this->order['order_id'];
    }

    public function getOrderLineId(): ?string
    {
        return null;
    }

    public function getAmount(): float
    {
        return $this->order['amount'];
    }

    public function getCurrency(): string
    {
        return $this->order['currency_code'];
    }
}
