<?php

namespace App\Entity;

use App\Service\StripeAmount;

abstract class MiraklOrder
{
    protected array $order;

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

    abstract public function isTaxIncluded(): bool;

    abstract public function isPaid(): bool;

    abstract public function isAborted(): bool;

    abstract public function isAccepted(): bool;

    abstract public function getAmountDue(): float;

    abstract public function getAbortedAmount(): float;

    abstract public function getOperatorCommission(): float;

    abstract public function getRefundedOperatorCommission(StripeRefund $refund): float;

    abstract protected function findRefundDetails(StripeRefund $refund): ?array;

    public function hasRefund(StripeRefund $refund): bool
    {
        return null !== $this->findRefundDetails($refund);
    }

    public function getRefundedTax(StripeRefund $refund): float
    {
        $details = $this->findRefundDetails($refund);
        $taxes = array_merge($details['shipping_taxes'] ?? [], $details['taxes'] ?? []);

        return array_sum(array_column($taxes, 'amount'));
    }

    public function isTaxExcluded(): bool
    {
        return 'TAX_EXCLUDED' === ($this->order['order_tax_mode'] ?? null);
    }

    public function getAdditionalRefundTax(StripeRefund $refund): int
    {
        if (!$this->isTaxExcluded()) {
            return 0;
        }

        $tax = StripeAmount::toMinorUnits($this->getRefundedTax($refund), $refund->getCurrency());
        $details = $this->findRefundDetails($refund);
        // The pending refund can already match the gross amount from the order details.
        if (0 !== $tax && isset($details['amount'])) {
            $net = StripeAmount::toMinorUnits($details['amount'], $refund->getCurrency());
            if ($refund->getMiraklAmount() === $net + $tax) {
                return 0;
            }
        }

        return $tax;
    }

    abstract public function getCurrency(): string;

    abstract public function getOrderTaxTotal(): float;
}
