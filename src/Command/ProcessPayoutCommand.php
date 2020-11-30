<?php

namespace App\Command;

use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Exception\UndispatchableException;
use App\Message\ProcessPayoutMessage;
use App\Message\ProcessTransferMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessPayoutCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-payout';

    protected static $typeToAmountKey = [
        StripeTransfer::TRANSFER_SUBSCRIPTION => 'total_subscription_incl_tax',
        StripeTransfer::TRANSFER_EXTRA_CREDITS => 'total_other_credits_incl_tax',
        StripeTransfer::TRANSFER_EXTRA_INVOICES => 'total_other_invoices_incl_tax',
    ];

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    public function __construct(MessageBusInterface $bus, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripePayoutRepository $stripePayoutRepository, StripeTransferRepository $stripeTransferRepository, AccountMappingRepository $accountMappingRepository)
    {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->accountMappingRepository = $accountMappingRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_shop_id', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $miraklShopId = $input->getArgument('mirakl_shop_id');

        assert(null === $miraklShopId || is_string($miraklShopId));
        if (null == $miraklShopId) {
            $output->writeln('No mirakl shop id specified');
            $miraklShopId = '';
        }

        if ('' !== $miraklShopId) {
            $this->logger->info(
                'Executing with specific shop',
                [ 'shop_id' => $miraklShopId ]
            );
            $this->logger->info('Creating specified Mirakl invoices', ['mirakl_shop_id' => $miraklShopId]);
            $miraklInvoices = $this->miraklClient->listInvoicesByShopId($miraklShopId);
        } else {
            $lastMiraklUpdateTime = $this->stripePayoutRepository->getLastMiraklUpdateTime();

            if (null !== $lastMiraklUpdateTime) {
                $this->logger->info(
                    'Executing for recent invoices',
                    [ 'since' => $lastMiraklUpdateTime->format(MiraklClient::DATE_FORMAT) ]
                );
                $miraklInvoices = $this->miraklClient->listInvoicesByDate($lastMiraklUpdateTime);

                // get invoices ID for failed transfers & payouts
                $toBeRetriedInvoices = $this->getToBeRetriedInvoices();

                // add failed transfer & payout if not already returned by Mirakl
                $mirakleInvoiceIds = array_map(function ($mirakleInvoice) {
                    return $mirakleInvoice['invoice_id'];
                }, $miraklInvoices);

                foreach ($toBeRetriedInvoices as $toBeRetriedInvoice) {
                    if (false === array_search($toBeRetriedInvoice['invoice_id'], $mirakleInvoiceIds)) {
                        $mirakleInvoices[] = $toBeRetriedInvoice;
                    }
                }
            } else {
                $this->logger->info('Executing for all invoices');
                $miraklInvoices = $this->miraklClient->listInvoices();
            }
        }

        if (count($miraklInvoices) > 0) {
            $transfers = $this->prepareTransfers($miraklInvoices);
            $payouts = $this->preparePayouts($miraklInvoices);

            $this->dispatchTransfers($transfers);
            $this->dispatchPayouts($payouts);
        }

        return 0;
    }

    private function prepareTransfers(array $miraklInvoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findExistingTransfersByInvoiceIds(array_column($miraklInvoices, 'invoice_id'));

        $transfers = [];
        foreach ($miraklInvoices as $miraklInvoice) {
            $invoiceId = $miraklInvoice['invoice_id'];

            foreach (self::$typeToAmountKey as $type => $amountKey) {
                if (isset($existingTransfers[$invoiceId][$type])) {
                    // Use existing transfer
                    $transfer = $existingTransfers[$invoiceId][$type];

                    if ($transfer->getStatus() === StripeTransfer::TRANSFER_CREATED) {
                        $ignoreReason = sprintf(
                            'Skipping transfer of type %s with existing created transfer',
                            $type
                        );
                        $this->logger->info($ignoreReason, [ 'invoice_id' => $invoiceId ]);
                        continue;
                    }

                    if ($transfer->getTransferId()) {
                        // Should not happen but in case it does let's clean it up
                        $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
                        $ignoreReason = sprintf(
                            'Cleaning transfer of type %s with existing Stripe transfer',
                            $type
                        );
                        $this->logger->info($ignoreReason, [ 'invoice_id' => $invoiceId ]);
                        continue;
                    }

                    // Transfer to be retried for this invoice
                    $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
                    $transfer = $this->prepareTransfer($miraklInvoice, $transfer);
                } else {
                    // Initiating new transfer
                    $transfer = new StripeTransfer();
                    $transfer->setType($type);
                    $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
                    $transfer->setMiraklId($invoiceId);

                    $transfer = $this->prepareTransfer($miraklInvoice, $transfer);
                    if ($transfer === null) {
                        continue;
                    }

                    // Create new transfer
                    $this->stripeTransferRepository->persist($transfer);
                }

                $transfers[] = $transfer;
            }
        }

        // Save everything
        $this->stripeTransferRepository->flush();

        return $transfers;
    }

    private function prepareTransfer(array $miraklInvoice, StripeTransfer $transfer): ?StripeTransfer
    {
        if (0 == $transfer->getAmount()) {
            // Transfer amount
            $amountKey = self::$typeToAmountKey[$transfer->getType()];
            $rawAmount = $miraklInvoice['summary'][$amountKey] ?? 0;
            $amount = gmp_intval((string) ($rawAmount * 100));
            if ($amount == 0) {
                $this->logger->info('Transfer amount is 0. Nothing to dispatch.', [
                    'invoice_id' => $transfer->getMiraklId(),
                    'type' => $transfer->getType(),
                    'raw_amount' => $rawAmount
                ]);
                return null; // No need to persist transfer if amount = 0
            }

            $transfer->setAmount($amount);
            $transfer->setCurrency($miraklInvoice['currency_iso_code']);
        }

        if (!$transfer->getAccountMapping()) {
            // Seller ID
            $shopId = $miraklInvoice['shop_id'] ?? -1;
            $mapping = $this->accountMappingRepository->findOneBy([
                'miraklShopId' => $shopId,
            ]);

            if (!$mapping) {
                return $this->failTransfer($transfer, sprintf(
                    'Cannot find Stripe account for Seller ID %s',
                    $shopId
                ));
            }

            $transfer->setAccountMapping($mapping);
        }

        if (!$transfer->getMiraklUpdateTime()) {
            // Mirakl update time
            $miraklUpdateTime = \DateTime::createFromFormat(
                MiraklClient::DATE_FORMAT,
                $miraklInvoice['end_time']
            );

            if (!$miraklUpdateTime) {
                $transfer->setMiraklUpdateTime(new \DateTime('2000-01-01')); // can't be null
                return $this->failTransfer($transfer, sprintf(
                    'Cannot parse last_updated_date %s',
                    $miraklInvoice['end_time']
                ));
            }

            $transfer->setMiraklUpdateTime($miraklUpdateTime);
        }

        return $transfer;
    }

    private function preparePayouts(array $miraklInvoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingPayouts = $this->stripePayoutRepository
            ->findExistingPayoutsByInvoiceIds(array_column($miraklInvoices, 'invoice_id'));

        $payouts = [];
        foreach ($miraklInvoices as $miraklInvoice) {
            $invoiceId = $miraklInvoice['invoice_id'];
            if (isset($existingPayouts[$invoiceId])) {
                // Use existing payout
                $payout = $existingPayouts[$invoiceId];

                if ($payout->getStatus() == StripePayout::PAYOUT_CREATED) {
                    $ignoreReason = 'Skipping payout with existing created payout';
                    $this->logger->info($ignoreReason, [ 'invoice_id' => $invoiceId ]);
                    continue;
                }

                if ($payout->getStripePayoutId()) {
                    // Should not happen but in case it does let's clean it up
                    $payout->setStatus(StripePayout::PAYOUT_CREATED);
                    $ignoreReason = 'Cleaning payout with existing Stripe payout';
                    $this->logger->info($ignoreReason, [ 'invoice_id' => $invoiceId ]);
                    continue;
                }

                // Payout to be retried for this invoice
                $payout->setStatus(StripePayout::PAYOUT_PENDING);
                $payout = $this->preparePayout($miraklInvoice, $payout);
            } else {
                // Initiating new payout
                $payout = new StripePayout();
                $payout->setStatus(StripePayout::PAYOUT_PENDING);
                $payout->setMiraklInvoiceId($invoiceId);

                $payout = $this->preparePayout($miraklInvoice, $payout);
                if ($payout === null) {
                    continue;
                }

                // Create new payout
                $this->stripePayoutRepository->persist($payout);
            }

            $payouts[] = $payout;
        }

        // Save everything
        $this->stripePayoutRepository->flush();

        return $payouts;
    }

    private function preparePayout(array $miraklInvoice, StripePayout $payout): ?StripePayout
    {
        if (!$payout->getAmount()) {
            // Payout amount
            $rawAmount = $miraklInvoice['summary']['amount_transferred'] ?? 0;
            $amount = gmp_intval((string) ($rawAmount * 100));
            if ($amount == 0) {
                $this->logger->info('Payout amount is 0. Nothing to dispatch.', [
                    'invoice_id' => $payout->getMiraklInvoiceId(),
                    'raw_amount' => $rawAmount
                ]);
                return null; // No need to persist payout if amount = 0
            }

            $payout->setAmount($amount);
            $payout->setCurrency($miraklInvoice['currency_iso_code']);
        }

        if (!$payout->getMiraklUpdateTime()) {
            // Mirakl update time
            $miraklUpdateTime = \DateTime::createFromFormat(
                MiraklClient::DATE_FORMAT,
                $miraklInvoice['end_time']
            );

            if (!$miraklUpdateTime) {
                $payout->setMiraklUpdateTime(new \DateTime('2000-01-01')); // can't be null
                return $this->failPayout($payout, sprintf(
                    'Cannot parse last_updated_date %s',
                    $miraklInvoice['end_time']
                ));
            }

            $payout->setMiraklUpdateTime($miraklUpdateTime);
        }

        if (!$payout->getAccountMapping()) {
            // Seller ID
            $shopId = $miraklInvoice['shop_id'];
            $mapping = $this->accountMappingRepository->findOneBy([
                'miraklShopId' => $shopId,
            ]);

            if (!$mapping) {
                return $this->failPayout($payout, sprintf(
                    'Cannot find Stripe account for Seller ID %s',
                    $shopId
                ));
            }

            $payout->setAccountMapping($mapping);
        }

        return $payout;
    }

    private function failTransfer($transfer, $reason): StripeTransfer
    {
        $this->logger->error($reason, ['invoice_id' => $transfer->getMiraklId()]);

        $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
        $transfer->setFailedReason($reason);

        return $transfer;
    }

    private function failPayout($payout, $reason): StripePayout
    {
        $this->logger->error($reason, ['invoice_id' => $payout->getMiraklInvoiceId()]);

        $payout->setStatus(StripePayout::PAYOUT_FAILED);
        $payout->setFailedReason($reason);

        return $payout;
    }

    private function dispatchTransfers(array $transfers): void
    {
        foreach ($transfers as $transfer) {
            $this->bus->dispatch(new ProcessTransferMessage(
                $transfer->getType(),
                $transfer->getId()
            ));
        }
    }

    private function dispatchPayouts(array $payouts): void
    {
        foreach ($payouts as $payout) {
            $this->bus->dispatch(new ProcessPayoutMessage(
                $payout->getId()
            ));
        }
    }

    private function getToBeRetriedInvoices(): array
    {
        $invoices = $this->getInvoicesForFailedTransfers();

        $invoices += $this->getInvoicesForFailedPayout();

        return $invoices;
    }

    private function getInvoicesForFailedTransfers(): array
    {
        $failedTransfers = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_FAILED,
            'type' => array_keys(self::$typeToAmountKey),
        ]);

        $invoices = [];

        foreach ($failedTransfers as $failedTransfer) {
            $id = (int) $failedTransfer->getMiraklId();
            $invoices[$id] = [
                'invoice_id' => $id,
            ];
        }

        return  $invoices;
    }

    private function getInvoicesForFailedPayout(): array
    {
        $failedPayouts = $this->stripePayoutRepository->findBy(['status' => StripePayout::PAYOUT_FAILED]);

        $invoices = [];

        foreach ($failedPayouts as $failedPayout) {
            $id = $failedPayout->getMiraklInvoiceId();
            $invoices[$id] = [
                'invoice_id' => $id,
            ];
        }

        return  $invoices;
    }
}
