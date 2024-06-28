<?php

namespace App\Entity;

class MiraklServiceOrder extends MiraklOrder
{
    public function getId(): string
    {
        return $this->order['id'];
    }

    public function getCommercialId(): string
    {
        return $this->order['commercial_order_id'];
    }

    public function getCreationDate(): string
    {
        return $this->order['date_created'];
    }

    public function getState(): string
    {
        return $this->order['state'];
    }

    public function getShopId(): int
    {
        return (int) $this->order['shop']['id'];
    }

    public function getCustomerId(): string
    {
        return $this->order['customer']['id'];
    }

    public function getTransactionNumber(): string
    {
        throw new \Exception('No information about payment in service orders, use SPA11 instead.');
    }

    public function isValidated(): bool
    {
        return !in_array($this->getState(), [
            'WAITING_SCORING',
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
        throw new \Exception('No information about payment in service orders, use SPA11 instead.');
    }

    public function isAborted(): bool
    {
        return in_array($this->getState(), ['ORDER_REFUSED', 'ORDER_EXPIRED', 'ORDER_CANCELLED']);
    }

    public function getAmountDue(): float
    {
        $taxes = 0;

        if (!$this->isTaxIncluded()) {
            foreach (($this->order['price']['taxes'] ?? []) as $tax) {
                $taxes += (float) $tax['amount'];
            }
        }

        $options = 0;
        foreach (($this->order['price']['options'] ?? []) as $option) {
            if ('BOOLEAN' === $option['type']) {
                $options += (float) $option['amount'];
            } elseif ('VALUE_LIST' === $option['type']) {
                foreach ($option['values'] as $value) {
                    $options += (float) $value['amount'];
                }
            }
        }

        return $this->order['price']['amount'] + $options + $taxes;
    }

    public function getAbortedAmount(): float
    {
        return $this->isAborted() ? $this->getAmountDue() : 0;
    }

    public function getOperatorCommission(): float
    {
        return $this->order['commission']['amount_including_taxes'] ?? 0;
    }

    public function getRefundedOperatorCommission(StripeRefund $refund): float
    {
        foreach ($this->order['refunds'] as $orderRefund) {
            if ($refund->getMiraklRefundId() === $orderRefund['id']) {
                return $orderRefund['commission']['amount_including_taxes'] ?? 0;
            }
        }

        return 0;
    }

    public function getRefundedTax(StripeRefund $refund): float
    {
        foreach ($this->order['refunds'] as $orderRefund) {
            if ($refund->getMiraklRefundId() === $orderRefund['id']) {
                if ($refund->getMiraklRefundId() === $orderRefund['id']) {
                    return $this->getRefundLineTaxes($orderRefund); // $orderRefund['commission_total_amount'];
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
        return $this->order['currency_code'];
    }

    public function getOrderTaxTotal(): float
    {
        $taxes = 0;

        if (!$this->isTaxIncluded()) {
            foreach (($this->order['price']['taxes'] ?? []) as $tax) {
                $taxes += (float) $tax['amount'];
            }
        }

        return $taxes;
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
}
