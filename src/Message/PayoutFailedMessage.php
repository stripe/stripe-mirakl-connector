<?php

namespace App\Message;

use App\Entity\StripePayout;

class PayoutFailedMessage implements NotifiableMessageInterface
{
    /**
     * @var array
     */
    private $content;

    private static function getType(): string
    {
        return 'payout.failed';
    }

    public function __construct(StripePayout $payout)
    {
        $mapping = $payout->getAccountMapping();
        $stripeAccountId = $mapping ? $mapping->getStripeAccountId() : null;
        $miraklShopId = $mapping ? $mapping->getMiraklShopId() : null;

        $this->content = [
            'type' => self::getType(),
            'payload' => [
                'internalId' => $payout->getId(),
                'amount' => $payout->getAmount(),
                'currency' => $payout->getCurrency(),
                'miraklInvoiceId' => $payout->getMiraklInvoiceId(),
                'stripePayoutId' => $payout->getPayoutId(),
                'payoutId' => $payout->getPayoutId(),
                'status' => $payout->getStatus(),
                'failedReason' => $payout->getStatusReason(),
            ],
        ];
    }

    public function getContent(): array
    {
        return $this->content;
    }
}
