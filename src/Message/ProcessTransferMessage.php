<?php

namespace App\Message;

class ProcessTransferMessage
{
    /**
     * @var int
     */
    private $stripeTransferId;

    public function __construct(int $stripeTransferId)
    {
        $this->stripeTransferId = $stripeTransferId;
    }

    public function getStripeTransferId()
    {
        return $this->stripeTransferId;
    }
}
