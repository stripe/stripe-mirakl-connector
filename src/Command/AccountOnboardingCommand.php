<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Factory\MiraklPatchShopFactory;
use App\Factory\AccountOnboardingFactory;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AccountOnboardingCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:sync:onboarding';
    protected const MAX_MIRAKL_BATCH_SIZE = 100;
    protected const DELAY_ARGUMENT_NAME = 'delay';

    /**
     * @var AccountOnboardingFactory
     */
    private $accountOnboardingFactory;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var MiraklPatchShopFactory
     */
    private $patchFactory;

    /**
     * @var string
     */
    private $customFieldCode;

    public function __construct(
        AccountOnboardingFactory $accountOnboardingFactory,
        MiraklClient $miraklClient,
        MiraklPatchShopFactory $patchFactory,
        string $customFieldCode
    ) {
        $this->accountOnboardingFactory = $accountOnboardingFactory;
        $this->miraklClient = $miraklClient;
        $this->patchFactory = $patchFactory;
        $this->customFieldCode = $customFieldCode;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Generates Stripe Express onboarding links for new Mirakl Shops.')
            ->setHelp('This command will fetch Mirakl shops newly created, without any link in the configured custom field, will generate a link and update the Mirakl custom field.')
            ->addArgument(self::DELAY_ARGUMENT_NAME, InputArgument::OPTIONAL, 'Fetch shops updated in the last <delay> minutes. If empty, fetches all Mirakl Shops.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger->info('starting');
        $this->logger->info('Updating Stripe Express onboarding links for new Mirakl sellers');
        $delay = intval($input->getArgument(self::DELAY_ARGUMENT_NAME));

        if ($delay > 0) {
            $now = \DateTime::createFromFormat('U', (string) time());
            assert(false !== $now); // PHPStan helper
            $lastMapping = $now->modify(sprintf('-%d minutes', $delay));
        } else {
            $lastMapping = null;
        }

        $shopsToCheck = $this->miraklClient->fetchShops(null, $lastMapping, false);
        $this->logger->info(sprintf('Found %d potentially new Mirakl Shops', count($shopsToCheck)));

        $shopsToUpdate = array_filter(array_map([$this, 'generateShopPatch'], $shopsToCheck));
        $this->logger->info(sprintf('Updating %d new Mirakl Shops', count($shopsToUpdate)));

        if (count($shopsToUpdate) > 0) {
            $maxBatchSizeShops = array_chunk($shopsToUpdate, self::MAX_MIRAKL_BATCH_SIZE);
            foreach ($maxBatchSizeShops as $shopsChunk) {
                $this->miraklClient->patchShops($shopsChunk);
            }
        }

        $this->logger->info('job succeeded');
        return 0;
    }

    private function getStripeCustomFieldValue(array $additionalFields): ?string
    {
        foreach ($additionalFields as $field) {
            if ($field['code'] === $this->customFieldCode) {
                return $field['value'];
            }
        }

        return null;
    }

    private function generateShopPatch(array $miraklShop): ?array
    {
        $fieldValue = $this->getStripeCustomFieldValue($miraklShop['shop_additional_fields']);
        if (null !== $fieldValue) {
            // Link has already been generated, skip
            return null;
        }

        try {
            $stripeUrl = $this->accountOnboardingFactory->createFromMiraklShop($miraklShop);

            return $this->patchFactory
                ->setMiraklShopId($miraklShop['shop_id'])
                ->setStripeUrl($stripeUrl)
                ->buildPatch();
        } catch (InvalidArgumentException $e) {
            // Mirakl Shop is already linked to an existing Stripe account.
            $this->logger->error($e->getMessage(), [
                'miraklShopId' => $miraklShop['shop_id'],
            ]);
        }

        return null;
    }
}
