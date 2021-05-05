<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Message\ProcessTransferMessage;
use App\Message\ProcessPayoutMessage;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\SellerSettlementService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SellerSettlementCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-payout';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var ConfigService
     */
    private $configService;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var SellerSettlementService
     */
    private $sellerSettlementService;

    public function __construct(
        MessageBusInterface $bus,
        ConfigService $configService,
        MiraklClient $miraklClient,
        SellerSettlementService $sellerSettlementService
    ) {
        $this->bus = $bus;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->sellerSettlementService = $sellerSettlementService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_shop_id', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->logger->info('starting');
        // Shop ID passed as argument
        $shopId = $input->getArgument('mirakl_shop_id');
        if (is_numeric($shopId)) {
            $this->processProvidedShopId((int) $shopId);
            $this->logger->info('job succeeded');
            return 0;
        }

        // Start with transfers and payouts that couldn't be completed before, 10 at a time
        $this->processBacklog();

        // Now up to 100 new invoices
        $this->processNewInvoices();

        $this->logger->info('job succeeded');
        return 0;
    }

    private function processProvidedShopId(int $shopId)
    {
        $this->logger->info('Executing provided shop', [ 'shop_id' => $shopId ]);
        $invoices = $this->miraklClient->listInvoicesByShopId($shopId);

        $this->dispatchTransfers(
            $this->sellerSettlementService->getTransfersFromInvoices($invoices)
        );

        $this->dispatchPayouts(
            $this->sellerSettlementService->getPayoutsFromInvoices($invoices)
        );
    }

    private function processBacklog()
    {
        $this->logger->info('Executing backlog');
        $retriableTransfers = $this->sellerSettlementService->getRetriableTransfers();
        $retriablePayouts = $this->sellerSettlementService->getRetriablePayouts();
        if (empty($retriableTransfers) && empty($retriablePayouts)) {
            $this->logger->info('No backlog');
            return;
        }

        // No invoice ID filter, let's use the earliest creation date
        $firstDateCreated = $this->getFirstInvoiceDate($retriableTransfers, $retriablePayouts);
        $invoices = $this->miraklClient->listInvoicesByDate($firstDateCreated);

        $transfersByInvoiceId = $this->sellerSettlementService
                            ->updateTransfersFromInvoices($retriableTransfers, $invoices);
        $this->dispatchTransfers($transfersByInvoiceId);

        $payouts = $this->sellerSettlementService
                            ->updatePayoutsFromInvoices($retriablePayouts, $invoices);
        $this->dispatchPayouts($payouts);
    }

    private function getFirstInvoiceDate(array $transfersByInvoiceId, array $payouts)
    {
        $createdDates = array_map(
            function ($o) {
                return $o->getMiraklCreatedDate();
            },
            array_merge($this->flattenTransfers($transfersByInvoiceId), $payouts)
        );

        sort($createdDates);
        return MiraklClient::getStringFromDatetime(current($createdDates));
    }

    private function processNewInvoices()
    {
        $checkpoint = $this->configService->getSellerSettlementCheckpoint() ?? '';
        $this->logger->info('Executing for recent invoices, checkpoint: ' . $checkpoint);
        if ($checkpoint) {
            $invoices = $this->miraklClient->listInvoicesByDate($checkpoint);
        } else {
            $invoices = $this->miraklClient->listInvoices();
        }
                
        if (empty($invoices)) {
            $this->logger->info('No new invoice');
            return;
        }

        $this->dispatchTransfers(
            $this->sellerSettlementService->getTransfersFromInvoices($invoices)
        );

        $this->dispatchPayouts(
            $this->sellerSettlementService->getPayoutsFromInvoices($invoices)
        );

        $checkpoint = $this->updateCheckpoint($invoices, $checkpoint);
        $this->configService->setSellerSettlementCheckpoint($checkpoint);
        $this->logger->info('Setting new checkpoint: ' . $checkpoint);
    }

    // Return the last valid date_created or the current checkpoint
    private function updateCheckpoint(array $invoices, ?string $checkpoint): ?string
    {
        $invoices = array_reverse($invoices);
        foreach ($invoices as $invoice) {
            try {
                MiraklClient::getDatetimeFromString($invoice['date_created']);
                return $invoice['date_created'];
            } catch (InvalidArgumentException $e) {
                // Shouldn't happen, see MiraklClient::getDatetimeFromString
            }
        }

        return $checkpoint;
    }

    private function dispatchTransfers(array $transfersByInvoiceId): void
    {
        foreach ($this->flattenTransfers($transfersByInvoiceId) as $transfer) {
            if ($transfer->isDispatchable()) {
                $this->bus->dispatch(new ProcessTransferMessage(
                    $transfer->getId()
                ));
            }
        }
    }

    private function dispatchPayouts(array $payouts): void
    {
        foreach ($payouts as $payout) {
            if ($payout->isDispatchable()) {
                $this->bus->dispatch(new ProcessPayoutMessage(
                    $payout->getId()
                ));
            }
        }
    }

    private function flattenTransfers(array $transfersByInvoiceId): array
    {
        $flat = [];
        foreach ($transfersByInvoiceId as $transfers) {
            $flat = array_merge($flat, array_values($transfers));
        }

        return $flat;
    }
}
