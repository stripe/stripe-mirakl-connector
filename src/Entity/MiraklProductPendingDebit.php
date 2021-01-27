<?php

namespace App\Entity;

class MiraklProductPendingDebit extends MiraklPendingDebit
{
    public function getOrderId(): string
    {
        return $this->order['order_id'];
    }

    public function getCommercialId(): string
    {
        return $this->order['order_commercial_id'];
    }

    public function getCustomerId(): string
    {
        return $this->order['customer_id'];
    }

    public function getTransactionNumber(): ?string
    {
        throw new \Exception('No transaction number in product order debits.');
    }

    public function isPaid(): bool
    {
        // Only unpaid pending debits are returned by Mirakl for product orders.
        return false;
    }

    public function getAmountDue(): float
    {
        return $this->order['amount'];
    }

    public function getCurrency(): string
    {
        return $this->order['currency_iso_code'];
    }
}
