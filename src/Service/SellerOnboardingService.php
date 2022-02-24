<?php

namespace App\Service;

use App\Entity\AccountMapping;
use App\Entity\MiraklShop;
use App\Repository\AccountMappingRepository;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpClient\Exception\ClientException;

class SellerOnboardingService
{

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var bool
     */
    private $stripePrefillOnboarding;

    /**
     * @var string
     */
    private $customFieldCode;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        bool $stripePrefillOnboarding,
        string $customFieldCode
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->stripePrefillOnboarding = $stripePrefillOnboarding;
        $this->customFieldCode = $customFieldCode;
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

            $this->accountMappingRepository->persistAndFlush($accountMapping);
        }

        return $accountMapping;
    }

    /**
     * @param MiraklShop $shop
     * @return Account
     * @throws ApiErrorException
     */
    protected function createStripeAccountFromShop(MiraklShop $shop): Account
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

    /**
     * @param MiraklShop $shop
     * @param AccountMapping $accountMapping
     * @return ?string New URL if updated successfully.
     * @throws ApiErrorException|ClientException
     */
    public function updateCustomField(MiraklShop $shop, AccountMapping $accountMapping): ?string
    {
        // Ignore if custom field already has a value other than the oauth URL (for backward compatibility)
        $shopId = $shop->getId();
        $customFieldValue = $shop->getCustomFieldValue($this->customFieldCode);
        if (!empty($customFieldValue) && !strpos($customFieldValue, 'express/oauth/authorize')) {
            return null;
        }

        // Add new AccountLink to Mirakl Shop
        $accountLink = $this->stripeClient->createAccountLink($accountMapping->getStripeAccountId());
        $this->miraklClient->updateShopCustomField($shopId, $this->customFieldCode, $accountLink['url']);
        return $accountLink['url'];
    }
}
