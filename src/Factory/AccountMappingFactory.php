<?php

namespace App\Factory;

use App\DTO\AccountMappingDTO;
use App\Entity\AccountMapping;

class AccountMappingFactory
{
    public function createMappingFromDTO(AccountMappingDTO $dto): AccountMapping
    {
        $mapping = new AccountMapping();
        $mapping->setMiraklShopId($dto->getMiraklShopId());
        $mapping->setStripeAccountId($dto->getStripeUserId());

        return $mapping;
    }
}
