<?php

namespace App\Service;

use App\Entity\AccountMapping;
use App\Entity\MiraklShop;
use App\Repository\AccountMappingRepository;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

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
     * @var RouterInterface
     */
    private $router;

    /**
     * @var string
     */
    private $redirectOnboarding;

    /**
     * @var bool
     */
    private $stripePrefillOnboarding;

    /**
     * @var string
     */
    private $customFieldCode;

    /**
     * @var string
     */
    private $ignoredShopFieldCode;

    /**
     * @var string
     */
    private $stripeAccountMetadata;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        RouterInterface $router,
        string $redirectOnboarding,
        bool $stripePrefillOnboarding,
        string $customFieldCode,
        string $ignoredShopFieldCode,
        string $stripeAccountMetadata
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->router = $router;
        $this->redirectOnboarding = $redirectOnboarding;
        $this->stripePrefillOnboarding = $stripePrefillOnboarding;
        $this->customFieldCode = $customFieldCode;
        $this->ignoredShopFieldCode = $ignoredShopFieldCode;
        $this->stripeAccountMetadata = $stripeAccountMetadata;
    }

    /**
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
            $accountMapping->setPayoutEnabled((bool) $stripeAccount->payouts_enabled);
            if (isset($stripeAccount->requirements->disabled_reason)) {
                $accountMapping->setDisabledReason($stripeAccount->requirements->disabled_reason);
            }
            $accountMapping->setPayinEnabled((bool) $stripeAccount->charges_enabled);
            $this->accountMappingRepository->persistAndFlush($accountMapping);
        } else {
            $stripeAccount = $this->stripeClient->retrieveAccount($accountMapping->getStripeAccountId());
            if ($stripeAccount && $stripeAccount->id) {
                $this->updateStripeAccountFromShop($shop, $stripeAccount);
            }
        }

        return $accountMapping;
    }

    public function updateAccountMappingIgnored(AccountMapping $accountMapping, bool $ignored): void
    {
        $accountMapping->setIgnored($ignored);
        $this->accountMappingRepository->persistAndFlush($accountMapping);
    }

    protected function updateStripeAccountFromShop(MiraklShop $shop, Account $stripeAccount)
    {
        $additionalMetaDataFields = $this->getAdditionalMetaDataFields($shop);
        $this->stripeClient->updateStripeAccount($stripeAccount->id, [], $additionalMetaDataFields);
    }

    /**
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
                    'support_email' => $rawShop['contact_informations']['email'] ?? null
                ],
            ];

            if ($rawShop['contact_informations']['phone'] && $rawShop['contact_informations']['phone'] != '') {
                $details['business_profile']['support_phone'] = $rawShop['contact_informations']['phone'];
            }
        }

        $additionalMetaDataFields = $this->getAdditionalMetaDataFields($shop);

        $metaData = array_merge($additionalMetaDataFields, [
            'miraklShopId' => $shop->getId()
        ]);

        return $this->stripeClient->createStripeAccount($shop->getId(), $details, $metaData);
    }

    /**
     * @param $shop
     * @return array
     */
    private function getAdditionalMetaDataFields($shop)
    {
        $additionalMetaDataFields = [];
        if ($this->stripeAccountMetadata) {
            $shopData = $shop->getShop();
            $additionalMetaData = json_decode($this->stripeAccountMetadata);

            foreach ($additionalMetaData as $fieldKey => $fieldValue) {
                if (isset($shopData[$fieldKey])) {
                    $additionalMetaDataFields[$fieldValue] = $shopData[$fieldKey];
                }
            }
        }

        return $additionalMetaDataFields;
    }

    /**
     * @return ?string the custom field value
     */
    public function getCustomFieldValue(MiraklShop $shop): ?string
    {
        return $shop->getCustomFieldValue($this->customFieldCode);
    }

    /**
     * @return bool true if the field is set and the shop ignored, false otherwise
     */
    public function isShopIgnored(MiraklShop $shop): bool
    {
        return 'true' === $shop->getCustomFieldValue($this->ignoredShopFieldCode);
    }

    /**
     * @return string new LoginLink URL
     *
     * @throws ApiErrorException|ClientException
     */
    public function addLoginLinkToShop(int $shopId, AccountMapping $accountMapping): string
    {
        // Create new LoginLink
        $url = $this->createLoginLink($accountMapping->getStripeAccountId());

        // Add AccountLink to Mirakl Shop
        $this->miraklClient->updateShopCustomField($shopId, $this->customFieldCode, $url);

        return $url;
    }

    /**
     * @return string new LoginLink URL
     *
     * @throws ApiErrorException
     */
    private function createLoginLink(string $accountId): string
    {
        $loginLink = $this->stripeClient->createLoginLink($accountId);

        return $loginLink['url'].'';
    }

    /**
     * @return string new AccountLink URL
     *
     * @throws ApiErrorException|ClientException
     */
    public function addOnboardingLinkToShop(int $shopId, AccountMapping $accountMapping): string
    {
        // Generate unique token
        $hasToken = null !== $accountMapping->getOnboardingToken();
        $token = $accountMapping->getOnboardingToken() ?? bin2hex(random_bytes(16));

        // Create new AccountLink
        $url = $this->createAccountLink($accountMapping->getStripeAccountId(), $token);

        // Add AccountLink to Mirakl Shop
        $this->miraklClient->updateShopCustomField($shopId, $this->customFieldCode, $url);

        // Persist token
        if (!$hasToken) {
            $accountMapping->setOnboardingToken($token);
            $this->accountMappingRepository->persistAndFlush($accountMapping);
        }

        return $url;
    }

    /**
     * @return string new AccountLink URL
     *
     * @throws ApiErrorException
     */
    private function createAccountLink(string $accountId, string $token): string
    {
        $accountLink = $this->stripeClient->createAccountLink(
            $accountId,
            $this->router->generate(
                'onboarding_refresh',
                ['token' => $token],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
            $this->redirectOnboarding
        );

        return $accountLink['url'].'';
    }
}
