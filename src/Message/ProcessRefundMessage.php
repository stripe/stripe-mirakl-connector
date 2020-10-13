<?php

namespace App\Message;

class ProcessRefundMessage
{
    /**
     * @var string
     */
    private $miraklRefundId;

    public function __construct(string $miraklRefundId)
    {
        $this->miraklRefundId = $miraklRefundId;
    }

    public function geMiraklRefundId(): string
    {
        return $this->miraklRefundId;
    }
}
