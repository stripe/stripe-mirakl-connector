<?php

namespace App\Service;

use App\Factory\StripeTransferFactory;
use App\Repository\StripeTransferRepository;

class PaymentSplitService
{
    /**
     * @var StripeTransferFactory
     */
    private $stripeTransferFactory;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    private $enablePaymentTaxSplit;
    private $taxOrderPostfix;

    public function __construct(
        StripeTransferFactory $stripeTransferFactory,
        StripeTransferRepository $stripeTransferRepository,
        bool $enablePaymentTaxSplit,
        string $taxOrderPostfix
    ) {
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->enablePaymentTaxSplit = $enablePaymentTaxSplit;
        $this->taxOrderPostfix = $taxOrderPostfix;
    }

    /**
     * @return array App\Entity\StripeTransfer[]
     */
    public function getRetriableProductTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableProductOrderTransfers();
    }

    /**
     * @return array App\Entity\StripeTransfer[]
     */
    public function getRetriableServiceTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableServiceOrderTransfers();
    }

    /**
     * @param array $orders
     * @return array App\Entity\StripeTransfer[]
     */
    public function getTransfersFromOrders(array $orders, array $pendingDebits): array
    {
        // Retrieve existing StripeTransfers with provided order IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findTransfersByOrderIds(array_keys($orders));

        $transfers = [];
        foreach ($orders as $orderId => $order) {
            $pendingDebit = $pendingDebits[$orderId] ?? null;
            if (isset($existingTransfers[$orderId])) {
                $transfer = $existingTransfers[$orderId];
                if (!$transfer->isRetriable()) {
                    continue;
                }

                // Use existing transfer
                $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order, $pendingDebit);
            } else {
                // Create new transfer
                $transfer = $this->stripeTransferFactory->createFromOrder($order, $pendingDebit);
                $this->stripeTransferRepository->persist($transfer);
                if ($this->enablePaymentTaxSplit) {
                    $tax_transfer = $this->stripeTransferFactory->createFromOrderForTax($order, $pendingDebit);
                    $this->stripeTransferRepository->persist($tax_transfer);
                }
            }

            $transfers[] = $transfer;
            if (isset($tax_transfer)) {
                $transfers[] = $tax_transfer;
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfers;
    }

    /**
     * @param array $existingTransfers
     * @param array $orders
     * @return array App\Entity\StripeTransfer[]
     */
    public function updateTransfersFromOrders(array $existingTransfers, array $orders, array $pendingDebits)
    {
        $updated = [];
        foreach ($existingTransfers as $orderId => $transfer) {
            $pendingDebit = $pendingDebits[$orderId] ?? null;
            if ($this->enablePaymentTaxSplit && strpos($orderId, $this->taxOrderPostfix) !== false) {
                $tax_suffixed_orderId = $orderId;
                $orderId = str_replace($this->taxOrderPostfix, "", $orderId);
                $pendingDebit = $pendingDebits[$orderId] ?? null;
                $updated[$tax_suffixed_orderId] = $this->stripeTransferFactory->updateFromOrder(
                    $transfer,
                    $orders[$orderId],
                    $pendingDebit,
                    true
                );
            } else {
                $updated[$orderId] = $this->stripeTransferFactory->updateFromOrder(
                    $transfer,
                    $orders[$orderId],
                    $pendingDebit
                );
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $updated;
    }
}
