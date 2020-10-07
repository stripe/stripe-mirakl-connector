<?php

namespace App\Factory;

use App\DTO\AccountMappingDTO;
use App\Entity\AccountMapping;

class AccountMappingFactory
{
    public function createMappingFromDTO(AccountMappingDTO $accountMappingDTO): AccountMapping
    {
        $mapping = new AccountMapping();

        $miraklShopId = $accountMappingDTO->getMiraklShopId();
        $stripeUserId = $accountMappingDTO->getStripeUserId();

        $mapping->setMiraklShopId($miraklShopId)
                ->setStripeAccountId($stripeUserId);

        return $mapping;
    }
}
