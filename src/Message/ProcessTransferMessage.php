<?php

namespace App\Message;

class ProcessTransferMessage
{
    /**
     * @var string
     */
    private $type;

    /**
     * @var int
     */
    private $stripeTransferId;

    public function __construct(string $type, int $stripeTransferId)
    {
        $this->type = $type;
        $this->stripeTransferId = $stripeTransferId;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getStripeTransferId()
    {
        return $this->stripeTransferId;
    }
}
