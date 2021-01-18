<?php

namespace App\Message;

use App\Entity\StripeRefund;

class RefundFailedMessage implements NotifiableMessageInterface
{
    /**
     * @var array
     */
    private $content;

    private static function getType(): string
    {
        return 'refund.failed';
    }

    public function __construct(StripeRefund $refund)
    {
        $this->content = [
            'type' => self::getType(),
            'payload' => [
                'internalId' => $refund->getId(),
                'amount' => $refund->getAmount(),
                'currency' => $refund->getCurrency(),
                'miraklOrderId' => $refund->getMiraklOrderId(),
                'miraklRefundId' => $refund->getMiraklRefundId(),
                'stripeRefundId' => $refund->getStripeRefundId(),
                'transactionId' => $refund->getTransactionId(),
                'status' => $refund->getStatus(),
                'failedReason' => $refund->getStatusReason(),
                'type' => $refund->getType(),
            ],
        ];
    }

    public function getContent(): array
    {
        return $this->content;
    }
}
