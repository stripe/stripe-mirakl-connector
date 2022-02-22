<?php

namespace App\Service;

use App\Entity\AccountMapping;
use App\Entity\MiraklShop;
use App\Repository\AccountMappingRepository;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;

class SellerOnboardingService
{

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var bool
     */
    private $stripePrefillOnboarding;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        StripeClient $stripeClient,
        bool $stripePrefillOnboarding
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripePrefillOnboarding = $stripePrefillOnboarding;
        $this->stripeClient = $stripeClient;
    }

    /**
     * @param MiraklShop $shop
     * @return AccountMapping
     * @throws ApiErrorException
     */
    public function getAccountMappingFromShop(MiraklShop $shop): AccountMapping
    {
        $accountMapping = current($this->accountMappingRepository->findByMiraklShopIds([$shop->getId()]));
        if (!$accountMapping) {
            // Create Express Account
            $stripeAccount = $this->createStripeAccountFromShop($shop);

            // Create AccountMapping
            $accountMapping = new AccountMapping();
            $accountMapping->setMiraklShopId($shop->getId());
            $accountMapping->setStripeAccountId($stripeAccount->id);
            $accountMapping->setPayoutEnabled($stripeAccount->payouts_enabled);
            $accountMapping->setDisabledReason($stripeAccount->requirements->disabled_reason);
            $accountMapping->setPayinEnabled($stripeAccount->charges_enabled);

            $accountMappings[$shop->getId()] = $this->accountMappingRepository->persistAndFlush($accountMapping);
        }

        return $accountMapping;
    }

    /**
     * @param MiraklShop $shop
     * @return Account
     */
    public function createStripeAccountFromShop(MiraklShop $shop): Account
    {
        $details = [];
        if ($this->stripePrefillOnboarding) {
            $rawShop = $shop->getShop();
            $details = [
                'business_type' => @$rawShop['is_professional'] ? 'company' : 'individual',
                'business_profile' => [
                    'name' => $rawShop['shop_name'] ?? null,
                    'url' => $rawShop['contact_informations']['web_site'] ?? null,
                    'support_email' => $rawShop['contact_informations']['email'] ?? null,
                    'support_phone' => $rawShop['contact_informations']['phone'] ?? null
                ],
            ];
        }

        return $this->stripeClient->createStripeAccount($shop->getId(), $details);
    }
}
