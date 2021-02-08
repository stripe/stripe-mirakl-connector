<?php

namespace App\Entity;

abstract class MiraklPendingDebit
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

    abstract public function getOrderId(): string;
    abstract public function getCommercialId(): string;
    abstract public function getCustomerId(): string;
    abstract public function getTransactionNumber(): ?string;

    abstract public function isPaid(): bool;
    abstract public function getAmountDue(): float;
    abstract public function getCurrency(): string;
}
