<?php

namespace App\Entity;

class MiraklProductPendingRefund extends MiraklPendingRefund
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
        return $this->order['order_line_id'];
    }

    public function getAmount(): float
    {
        return $this->order['amount'];
    }

    public function getCurrency(): string
    {
        return $this->order['currency_iso_code'];
    }
}
