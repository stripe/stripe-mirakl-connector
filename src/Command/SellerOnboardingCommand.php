<?php

namespace App\Command;

use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\SellerOnboardingService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\ClientException;

class SellerOnboardingCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:sync:onboarding';

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
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    public function __construct(
        ConfigService $configService,
        MiraklClient $miraklClient,
        SellerOnboardingService $sellerOnboardingService,
        bool $enableSellerOnboarding
    ) {
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->sellerOnboardingService = $sellerOnboardingService;
        $this->enableSellerOnboarding = $enableSellerOnboarding;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('delay', InputArgument::OPTIONAL, 'Deprecated argument kept for backward compatibility. Will be removed in future versions.');
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
            $this->logger->debug("Processing Mirakl Shop: $shopId.");

            // New checkpoint
            $newCheckpoint = $shop->getLastUpdatedDate();

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

            try {
                // Ignore if custom field already has a value other than the oauth URL (for backward compatibility)
                $customFieldValue = $this->sellerOnboardingService->getCustomFieldValue($shop);
                if (!empty($customFieldValue) && !strpos($customFieldValue, 'express/oauth/authorize')) {
                    $this->logger->debug("Ignoring Mirakl Shop $shopId with custom field already filled.");
                    continue;
                }

                // Add new AccountLink to custom field
                $url = $this->sellerOnboardingService->addOnboardingLinkToShop($shop->getId(), $accountMapping);
                $this->logger->info("Updated URL for Mirakl Shop $shopId to: $url.");
            } catch (ClientException $e) {
                $message = $e->getResponse()->getContent(false);
                $this->logger->error(sprintf('Could not add AccountLink to Mirakl Shop: %s.', $message), [
                    'shopId' => $shopId,
                    'accountId' => $accountMapping->getStripeAccountId()
                ]);
            } catch (ApiErrorException $e) {
                $this->logger->error(sprintf('Could not create Stripe AccountLink: %s.', $e->getMessage()), [
                    'shopId' => $shopId,
                    'accountId' => $accountMapping->getStripeAccountId(),
                    'stripeErrorCode' => $e->getStripeCode()
                ]);
            }
        }

        // Save new checkpoint
        if (isset($newCheckpoint) && $checkpoint !== $newCheckpoint) {
            $this->configService->setSellerOnboardingCheckpoint($newCheckpoint);
            $this->logger->info("Setting new checkpoint: $newCheckpoint.");
        }
    }
}
