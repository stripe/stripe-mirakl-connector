<?php

namespace App\Message;

class ProcessRefundMessage
{
    /**
     * @var string
     */
    private $stripeRefundId;

    public function __construct(string $stripeRefundId)
    {
        $this->stripeRefundId = $stripeRefundId;
    }

    public function getStripeRefundId(): string
    {
        return $this->stripeRefundId;
    }
}
