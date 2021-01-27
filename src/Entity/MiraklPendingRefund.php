<?php

namespace App\Entity;

abstract class MiraklPendingRefund
{
    protected $order;

    public function __construct(array $order)
    {
        $this->order = $order;
    }

    public function getOrder(): array
    {
        return $this->order;
    }

    abstract public function getId(): string;
    abstract public function getOrderId(): string;
    abstract public function getOrderLineId(): ?string;

    abstract public function getAmount(): float;
    abstract public function getCurrency(): string;
}
