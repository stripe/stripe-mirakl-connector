<?php

namespace App\Message;

class ProcessPayoutMessage
{
    /**
     * @var int
     */
    private $stripePayoutId;

    public function __construct(int $stripePayoutId)
    {
        $this->stripePayoutId = $stripePayoutId;
    }

    public function getStripePayoutId(): int
    {
        return $this->stripePayoutId;
    }
}
