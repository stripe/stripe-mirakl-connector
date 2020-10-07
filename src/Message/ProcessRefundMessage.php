<?php

namespace App\Message;

class ProcessRefundMessage
{
    /**
     * @var string
     */
    private $miraklRefundId;

    /**
     * @var int
     */
    private $commission;

    public function __construct(string $miraklRefundId, int $commission = 0)
    {
        $this->miraklRefundId = $miraklRefundId;
        $this->commission = $commission;
    }

    public function geMiraklRefundId(): string
    {
        return $this->miraklRefundId;
    }

    /**
     * @return int
     */
    public function getCommission(): int
    {
        return $this->commission;
    }
}
