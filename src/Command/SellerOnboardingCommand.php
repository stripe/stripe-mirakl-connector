<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\SellerOnboardingService;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Messenger\MessageBusInterface;

class SellerOnboardingCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:sync:onboarding';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var bool
     */
    private $enableSellerOnboarding;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    /**
     * @var string
     */
    private $customFieldCode;

    public function __construct(
        MessageBusInterface $bus,
        ConfigService $configService,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        SellerOnboardingService $sellerOnboardingService,
        bool $enableSellerOnboarding,
        string $customFieldCode
    ) {
        $this->bus = $bus;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->sellerOnboardingService = $sellerOnboardingService;
        $this->enableSellerOnboarding = $enableSellerOnboarding;
        $this->customFieldCode = $customFieldCode;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger->info('starting');
        if ($this->enableSellerOnboarding) {
            $this->processUpdatedShops();
        }

        $this->logger->info('job succeeded');
        return 0;
    }

    private function processUpdatedShops()
    {
        $checkpoint = $this->configService->getSellerOnboardingCheckpoint() ?? '';
        $this->logger->info("Processing recently updated shops, checkpoint: $checkpoint.");

        if ($checkpoint) {
            $shops = $this->miraklClient->listShopsByDate($checkpoint);
        } else {
            $shops = $this->miraklClient->listShops();
        }

        if (empty($shops)) {
            $this->logger->info("No shop recently updated.");
            return;
        }

        foreach ($shops as $shopId => $shop) {
            $this->logger->info("Processing Mirkal Shop: $shopId.");

            // Retrieve AccountMappings and create missing Stripe Accounts in the process
            try {
                $accountMapping = $this->sellerOnboardingService->getAccountMappingFromShop($shop);
            } catch (ApiErrorException $e) {
                $this->logger->error(sprintf('Could not create Stripe Account: %s.', $e->getMessage()), [
                    'shopId' => $shopId,
                    'stripeErrorCode' => $e->getStripeCode()
                ]);
                continue;
            }

            // Ignore if custom field already has a value other than the oauth URL (for backward compatibility)
            $customFieldValue = $shop->getCustomFieldValue($this->customFieldCode);
            if (!empty($customFieldValue) && !strpos($customFieldValue, 'express/oauth/authorize')) {
                continue;
            }

            // Add new AccountLink to Mirakl Shop
            $accountLink = $this->stripeClient->createAccountLink($accountMapping->getStripeAccountId());
            try {
                $this->miraklClient->updateShopCustomField($shopId, $this->customFieldCode, $accountLink['url']);
            } catch (ClientException $e) {
                $message = $e->getResponse()->getContent(false);
                $this->logger->error(sprintf('Could not add AccountLink to Mirakl Shop: %s.', $message), [
                    'shopId' => $shopId,
                    'accountLink' => $accountLink
                ]);
            }

            // New checkpoint
            $newCheckpoint = $shop->getLastUpdatedDate();
        }

        // Save new checkpoint
        if (isset($newCheckpoint) && $checkpoint !== $newCheckpoint) {
            $this->configService->setSellerOnboardingCheckpoint($newCheckpoint);
            $this->logger->info("Setting new checkpoint:  . $newCheckpoint.");
        }
    }
}
