<?php

namespace App\Factory;

use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Repository\AccountOnboardingRepository;
use App\Service\MiraklClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

class AccountOnboardingFactory
{
    public const STRIPE_EXPRESS_BASE_URI = 'https://connect.stripe.com/express/oauth/authorize';

    /**
     * @var AccountMappingRepository
     */
    private $mappingRepository;

    /**
     * @var AccountOnboardingRepository
     */
    private $accountOnboardingRepository;

    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var string
     */
    private $stripeClientId;

    /**
     * @var bool
     */
    private $stripePrefillOnboarding;

    public function __construct(
        AccountMappingRepository $mappingRepository,
        AccountOnboardingRepository $accountOnboardingRepository,
        RouterInterface $router,
        MiraklClient $miraklClient,
        string $stripeClientId,
        bool $stripePrefillOnboarding
    ) {
        $this->mappingRepository = $mappingRepository;
        $this->accountOnboardingRepository = $accountOnboardingRepository;
        $this->router = $router;
        $this->miraklClient = $miraklClient;
        $this->stripeClientId = $stripeClientId;
        $this->stripePrefillOnboarding = $stripePrefillOnboarding;
    }

    public function createFromMiraklShop(array $miraklShop): string
    {
        $miraklId = $miraklShop['shop_id'];
        $existingShop = $this->mappingRepository->findOneByMiraklShopId($miraklId);
        if ($existingShop) {
            throw new InvalidArgumentException('Shop ID already mapped to a Stripe Account');
        }

        $accountOnboarding = $this->accountOnboardingRepository->createAccountOnboarding($miraklId);

        $queryParams = [
            'state' => $accountOnboarding->getStripeState(),
            'client_id' => $this->stripeClientId,
            'redirect_uri' => $this->router->generate('create_mapping', [], UrlGeneratorInterface::ABSOLUTE_URL),
        ];

        if ($this->stripePrefillOnboarding) {
            $businessType = null;
            if (array_key_exists('is_professional', $miraklShop)) {
                $businessType = $miraklShop['is_professional'] ? 'company' : 'individual';
            }

            $queryParams = array_merge(array_filter([
                'email' => $miraklShop['contact_informations']['email'] ?? '',
                'url' => $miraklShop['contact_informations']['web_site'] ?? '',
                'country' => $miraklShop['contact_informations']['country'] ?? '',
                'phone_number' => $miraklShop['contact_informations']['phone'] ?? '',
                'business_name' => $miraklShop['shop_name'] ?? '',
                'business_type' => $businessType,
                'first_name' => $miraklShop['contact_informations']['firstname'] ?? '',
                'last_name' => $miraklShop['contact_informations']['lastname'] ?? '',
            ]), $queryParams);
        }

        return sprintf('%s?%s', self::STRIPE_EXPRESS_BASE_URI, \http_build_query($queryParams));
    }

    public function createFromMiraklShopId(int $miraklId): string
    {
        if ($this->stripePrefillOnboarding) {
            try {
                $miraklSellers = $this->miraklClient->fetchShops([$miraklId]);

                if (1 === count($miraklSellers)) {
                    return $this->createFromMiraklShop($miraklSellers[0]);
                }
            } catch (\Exception $e) {
                // Could not fetch info on Mirakl to prefill onboarding link
                // Continuing, but info will not be prefilled
            }
        }

        return $this->createFromMiraklShop(['shop_id' => $miraklId]);
    }
}
