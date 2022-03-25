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

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        RouterInterface $router,
        string $redirectOnboarding,
        bool $stripePrefillOnboarding,
        string $customFieldCode
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->router = $router;
        $this->redirectOnboarding = $redirectOnboarding;
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

        return $this->stripeClient->createStripeAccount($shop->getId(), $details, ['miraklShopId' => $shop->getId()]);
    }

    /**
     * @param MiraklShop $shop
     * @return ?string The custom field value.
     */
    public function getCustomFieldValue(MiraklShop $shop): ?string
    {
        return $shop->getCustomFieldValue($this->customFieldCode);
    }

    /**
     * @param int $shopId
     * @param AccountMapping $accountMapping
     * @return string New LoginLink URL.
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
     * @param string $accountId
     * @return string New LoginLink URL.
     * @throws ApiErrorException
     */
    private function createLoginLink(string $accountId): string
    {
        $loginLink = $this->stripeClient->createLoginLink($accountId);
        return $loginLink['url'];
    }

    /**
     * @param int $shopId
     * @param AccountMapping $accountMapping
     * @return string New AccountLink URL.
     * @throws ApiErrorException|ClientException
     */
    public function addOnboardingLinkToShop(int $shopId, AccountMapping $accountMapping): string
    {
        // Generate unique token
        $hasToken = $accountMapping->getOnboardingToken() !== null;
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
     * @param string $accountId
     * @param string $token
     * @return string New AccountLink URL.
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

        return $accountLink['url'];
    }
}
