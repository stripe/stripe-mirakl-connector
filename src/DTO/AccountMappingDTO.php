<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class AccountMappingDTO
{
    /**
     * @var int
     * @App\Validator\MiraklShopId()
     * @Assert\NotNull
     */
    private $miraklShopId;

    /**
     * @var string
     * @Assert\NotNull
     */
    private $stripeUserId;

    public function getMiraklShopId(): int
    {
        return $this->miraklShopId;
    }

    public function setMiraklShopId(int $miraklShopId): self
    {
        $this->miraklShopId = $miraklShopId;

        return $this;
    }

    public function getStripeUserId(): string
    {
        return $this->stripeUserId;
    }

    public function setStripeUserId(string $stripeUserId): self
    {
        $this->stripeUserId = $stripeUserId;

        return $this;
    }
}
