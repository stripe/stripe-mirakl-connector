<?php

namespace App\Message;

use App\Entity\StripeTransfer;

class TransferFailedMessage implements NotifiableMessageInterface
{
    /**
     * @var array
     */
    private $content;

    private static function getType(): string
    {
        return 'transfer.failed';
    }

    public function __construct(StripeTransfer $transfer)
    {
        $mapping = $transfer->getAccountMapping();
        $stripeAccountId = $mapping ? $mapping->getStripeAccountId() : null;
        $miraklShopId = $mapping ? $mapping->getMiraklShopId() : null;

        $this->content = [
            'type' => self::getType(),
            'payload' => [
                'internalId' => $transfer->getId(),
                'miraklId' => $transfer->getMiraklId(),
                'type' => $transfer->getType(),
                'stripeAccountId' => $stripeAccountId,
                'miraklShopId' => $miraklShopId,
                'transferId' => $transfer->getTransferId(),
                'transactionId' => $transfer->getTransactionId(),
                'amount' => $transfer->getAmount(),
                'status' => $transfer->getStatus(),
                'failedReason' => $transfer->getFailedReason(),
                'currency' => $transfer->getCurrency(),
            ],
        ];
    }

    public function getContent(): array
    {
        return $this->content;
    }
}
