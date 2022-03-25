<?php

namespace App\Service;

use App\Entity\StripeTransfer;
use App\Entity\StripePayout;
use App\Service\MiraklClient;
use App\Factory\StripePayoutFactory;
use App\Factory\StripeTransferFactory;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;

class SellerSettlementService
{
    /**
     * @var StripePayoutFactory
     */
    private $stripePayoutFactory;

    /**
     * @var StripeTransferFactory
     */
    private $stripeTransferFactory;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    public function __construct(
        StripePayoutFactory $stripePayoutFactory,
        StripeTransferFactory $stripeTransferFactory,
        StripePayoutRepository $stripePayoutRepository,
        StripeTransferRepository $stripeTransferRepository
    ) {
        $this->stripePayoutFactory = $stripePayoutFactory;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
    }

    /**
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function getRetriableTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableInvoiceTransfers();
    }

    /**
     * @param array $invoices
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function getTransfersFromInvoices(array $invoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findTransfersByInvoiceIds(array_keys($invoices));

        $transfersByInvoiceId = [];
        foreach ($invoices as $invoice) {
            foreach (StripeTransfer::getInvoiceTypes() as $type) {
                $invoiceId = $invoice['invoice_id'];
                if (isset($existingTransfers[$invoiceId][$type])) {
                    $transfer = $existingTransfers[$invoiceId][$type];
                    if (!$transfer->isRetriable()) {
                        continue;
                    }

                    // Use existing transfer
                    $transfer = $this->stripeTransferFactory
                        ->updateFromInvoice($transfer, $invoice, $type);
                } else {
                    // Create new transfer
                    $transfer = $this->stripeTransferFactory
                        ->createFromInvoice($invoice, $type);
                    $this->stripeTransferRepository->persist($transfer);
                }

                if (!isset($transfersByInvoiceId[$invoiceId])) {
                    $transfersByInvoiceId[$invoiceId] = [];
                }

                $transfersByInvoiceId[$invoiceId][$type] = $transfer;
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfersByInvoiceId;
    }

    /**
     * @param array $existingTransfers
     * @param array $invoices
     * @return array [ invoice_id => StripeTransfer[] ]
     */
    public function updateTransfersFromInvoices(array $existingTransfers, array $invoices)
    {
        $updated = [];
        foreach ($existingTransfers as $invoiceId => $transfers) {
            foreach ($transfers as $type => $transfer) {
                if (!isset($updated[$invoiceId])) {
                    $updated[$invoiceId] = [];
                }

                $updated[$invoiceId][$type] = $this->stripeTransferFactory->updateFromInvoice(
                    $transfer,
                    $invoices[(int) $invoiceId],
                    $type
                );
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $updated;
    }

    /**
     * @return array StripePayout[]
     */
    public function getRetriablePayouts(): array
    {
        return $this->stripePayoutRepository->findRetriablePayouts();
    }

    /**
     * @param array $invoices
     * @return array StripePayout[]
     */
    public function getPayoutsFromInvoices(array $invoices): array
    {
        // Retrieve existing StripeTransfers with provided invoice IDs
        $existingPayouts = $this->stripePayoutRepository
            ->findPayoutsByInvoiceIds(array_keys($invoices));

        $payouts = [];
        foreach ($invoices as $invoice) {
            $invoiceId = (int) $invoice['invoice_id'];
            if (isset($existingPayouts[$invoiceId])) {
                $payout = $existingPayouts[$invoiceId];
                if (!$payout->isRetriable()) {
                    continue;
                }

                // Use existing payout
                $payout = $this->stripePayoutFactory
                    ->updateFromInvoice($payout, $invoice);
            } else {
                // Create new payout
                $payout = $this->stripePayoutFactory
                    ->createFromInvoice($invoice);
                $this->stripePayoutRepository->persist($payout);
            }

            $payouts[] = $payout;
        }

        // Save
        $this->stripePayoutRepository->flush();

        return $payouts;
    }

    /**
     * @param array $existingPayouts
     * @param array $invoices
     * @return array StripePayout[]
     */
    public function updatePayoutsFromInvoices(array $existingPayouts, array $invoices)
    {
        $updated = [];
        foreach ($existingPayouts as $invoiceId => $payout) {
            $updated[$invoiceId] = $this->stripePayoutFactory->updateFromInvoice(
                $payout,
                $invoices[$invoiceId]
            );
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $updated;
    }
}
