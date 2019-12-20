<?php

namespace App\Factory;

use App\DTO\MiraklStripeMappingDTO;
use App\Entity\MiraklStripeMapping;

class MiraklStripeMappingFactory
{
    public function createMappingFromDTO(MiraklStripeMappingDTO $miraklStripeMappingDTO): MiraklStripeMapping
    {
        $mapping = new MiraklStripeMapping();

        $miraklShopId = $miraklStripeMappingDTO->getMiraklShopId();
        $stripeUserId = $miraklStripeMappingDTO->getStripeUserId();

        $mapping->setMiraklShopId($miraklShopId)
                ->setStripeAccountId($stripeUserId);

        return $mapping;
    }
}
