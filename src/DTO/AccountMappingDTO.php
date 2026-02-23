<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;
use App\Validator as AppAssert;

class AccountMappingDTO
{
    #[AppAssert\MiraklShopId]
    #[Assert\NotNull]
    private $miraklShopId;

    #[Assert\NotNull]
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
