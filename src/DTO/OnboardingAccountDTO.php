<?php

namespace App\DTO;

use Symfony\Component\Validator\Constraints as Assert;

class OnboardingAccountDTO
{
    /**
     * @var int
     * @App\Validator\MiraklShopId()
     * @Assert\NotNull
     */
    private $miraklShopId;

    public function getMiraklShopId(): int
    {
        return $this->miraklShopId;
    }

    public function setMiraklShopId(int $miraklShopId): self
    {
        $this->miraklShopId = $miraklShopId;

        return $this;
    }
}
