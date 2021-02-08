<?php

namespace App\Entity;

class MiraklServicePendingDebit extends MiraklPendingDebit
{
    public function getOrderId(): string
    {
        return $this->order['order_id'];
    }

    public function getCommercialId(): string
    {
        throw new \Exception('No commercial ID in service order debits.');
    }

    public function getCustomerId(): string
    {
        return $this->order['customer_id'];
    }

    public function getTransactionNumber(): ?string
    {
        return $this->order['transaction_number'] ?? null;
    }

    public function isPaid(): bool
    {
        return $this->order['state'] === 'OK';
    }

    public function getAmountDue(): float
    {
        return $this->order['amount'];
    }

    public function getCurrency(): string
    {
        return $this->order['currency_code'];
    }
}
