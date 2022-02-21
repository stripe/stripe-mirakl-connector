<?php

namespace App\Entity;

abstract class MiraklOrder
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

    public function getCreationDateAsDateTime(): \DateTime
    {
        return new \DateTime($this->getCreationDate());
    }

    abstract public function getId(): string;
    abstract public function getCommercialId(): string;
    abstract public function getCreationDate(): string;
    abstract public function getState(): string;
    abstract public function getShopId(): int;
    abstract public function getCustomerId(): string;
    abstract public function getTransactionNumber(): string;

    abstract public function isValidated(): bool;
    abstract public function isPaid(): bool;
    abstract public function isAborted(): bool;
    abstract public function getAmountDue(): float;
    abstract public function getAbortedAmount(): float;
    abstract public function getOperatorCommission(): float;
    abstract public function getRefundedOperatorCommission(StripeRefund $refund): float;
    abstract public function getCurrency(): string;
}
