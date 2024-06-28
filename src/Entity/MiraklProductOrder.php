<?php

namespace App\Entity;

class MiraklProductOrder extends MiraklOrder
{
    public function getId(): string
    {
        return $this->order['order_id'];
    }

    public function getCommercialId(): string
    {
        return $this->order['commercial_id'];
    }

    public function getCreationDate(): string
    {
        return $this->order['created_date'];
    }

    public function getState(): string
    {
        return $this->order['order_state'];
    }

    public function getShopId(): int
    {
        return (int) $this->order['shop_id'];
    }

    public function getCustomerId(): string
    {
        return $this->order['customer']['customer_id'];
    }

    public function getTransactionNumber(): string
    {
        return $this->order['transaction_number'] ?? '';
    }

    public function isValidated(): bool
    {
        return !in_array($this->getState(), [
            'STAGING',
            'WAITING_ACCEPTANCE',
            'WAITING_DEBIT',
            'WAITING_DEBIT_PAYMENT',
        ]);
    }

    public function isTaxIncluded(): bool
    {
        return isset($this->order['order_tax_mode']) && 'TAX_INCLUDED' === $this->order['order_tax_mode'];
    }

    public function isPaid(): bool
    {
        return isset($this->order['customer_debited_date']) && !empty($this->order['customer_debited_date']);
    }

    public function isAborted(): bool
    {
        return in_array($this->getState(), ['REFUSED', 'CANCELED']);
    }

    public function getAmountDue(): float
    {
        $amount = $this->order['total_price']; // REFUSED/CANCELED are already not included

        // Don't add taxes to the total_price if taxes are included
        if ($this->isTaxIncluded()) {
            return $amount;
        }

        foreach ($this->getOrderLines() as $orderLine) {
            if (!in_array($orderLine['order_line_state'], ['REFUSED', 'CANCELED'])) {
                $amount += $this->getOrderLineTaxes($orderLine);
            }
        }

        return $amount;
    }

    public function getAbortedAmount(): float
    {
        $amount = 0;
        foreach ($this->getOrderLines() as $orderLine) {
            switch ($orderLine['order_line_state']) {
                case 'REFUSED':
                    $amount += (float) $orderLine['total_price'];

                    if (!$this->isTaxIncluded()) {
                        $amount += (float) $this->getOrderLineTaxes($orderLine);
                    }
                    break;
                case 'CANCELED':
                    $amount += (float) $this->getOrderLineCanceledAmountWithTaxes($orderLine['cancelations']);
                    break;
            }
        }

        return $amount;
    }

    public function getOperatorCommission(): float
    {
        return $this->order['total_commission'];
    }

    public function getRefundedOperatorCommission(StripeRefund $refund): float
    {
        foreach ($this->getOrderLines() as $line) {
            if ($refund->getMiraklOrderLineId() === $line['order_line_id']) {
                foreach ($line['refunds'] as $orderRefund) {
                    if ($refund->getMiraklRefundId() === $orderRefund['id']) {
                        return $orderRefund['commission_total_amount'];
                    }
                }
            }
        }

        return 0;
    }

    public function getRefundedTax(StripeRefund $refund): float
    {
        foreach ($this->getOrderLines() as $line) {
            if ($refund->getMiraklOrderLineId() === $line['order_line_id']) {
                foreach ($line['refunds'] as $orderRefund) {
                    if ($refund->getMiraklRefundId() === $orderRefund['id']) {
                        $tax = $this->getRefundLineTaxes($orderRefund);

                        return $tax;
                    }
                }
            }
        }

        return 0;
    }

    protected function getRefundLineTaxes(array $refundLine): float
    {
        $taxes = 0;
        $allTaxes = array_merge($refundLine['shipping_taxes'] ?? [], $refundLine['taxes'] ?? []);
        foreach ($allTaxes as $tax) {
            $taxes += (float) $tax['amount'];
        }

        return $taxes;
    }

    public function getCurrency(): string
    {
        return $this->order['currency_iso_code'];
    }

    public function getOrderLines(): array
    {
        return $this->order['order_lines'] ?? [];
    }

    protected function getOrderLineTaxes(array $orderLine): float
    {
        $taxes = 0;
        $allTaxes = array_merge($orderLine['shipping_taxes'] ?? [], $orderLine['taxes'] ?? []);
        foreach ($allTaxes as $tax) {
            $taxes += (float) $tax['amount'];
        }

        return $taxes;
    }

    protected function getOrderLineCanceledAmountWithTaxes(array $canceledOrderLines): float
    {
        $canceledAmount = 0;
        foreach ($canceledOrderLines as $orderLine) {
            $canceledAmount += (float) $orderLine['amount'];
            if (!$this->isTaxIncluded()) {
                $canceledAmount += $this->getOrderLineTaxes($orderLine);
            }
        }

        return $canceledAmount;
    }

    public function getOrderTaxTotal(): float
    {
        $amount = 0;
        foreach ($this->getOrderLines() as $orderLine) {
            if (!in_array($orderLine['order_line_state'], ['REFUSED', 'CANCELED'])) {
                $amount += $this->getOrderLineOrderTaxes($orderLine);
            }
        }

        return $amount;
    }

    protected function getOrderLineOrderTaxes(array $orderLine): float
    {
        $taxes = 0;
        $allTaxes = $orderLine['taxes'] ?? [];
        foreach ($allTaxes as $tax) {
            $taxes += (float) $tax['amount'];
        }

        $shippingTaxes = $orderLine['shipping_taxes'] ?? [];
        foreach ($shippingTaxes as $shippingTax) {
            $taxes += (float) $shippingTax['amount'];
        }

        return $taxes;
    }

    public function getTotalTypeTaxes($type): float
    {
        $amount = 0;
        foreach ($this->getOrderLines() as $orderLine) {
            if (!in_array($orderLine['order_line_state'], ['REFUSED', 'CANCELED'])) {
                $amount += $this->getOrderLineTypeTaxes($orderLine, $type);
            }
        }

        return $amount;
    }

    protected function getOrderLineTypeTaxes(array $orderLine, $type): float
    {
        $taxes = 0;
        $allTaxes = $orderLine[$type] ?? [];
        foreach ($allTaxes as $tax) {
            $taxes += (float) $tax['amount'];
        }

        return $taxes;
    }
}
