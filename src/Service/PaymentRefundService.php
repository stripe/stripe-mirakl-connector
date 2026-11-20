<?php

namespace App\Service;

use App\Entity\StripeTransfer;
use App\Factory\StripeRefundFactory;
use App\Factory\StripeTransferFactory;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;

class PaymentRefundService
{
    /**
     * @var StripeRefundFactory
     */
    private $stripeRefundFactory;

    /**
     * @var StripeTransferFactory
     */
    private $stripeTransferFactory;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    private $taxOrderPostfix;
    private $enablePaymentTaxSplit;

    public function __construct(
        StripeRefundFactory $stripeRefundFactory,
        StripeTransferFactory $stripeTransferFactory,
        StripeRefundRepository $stripeRefundRepository,
        StripeTransferRepository $stripeTransferRepository,
        string $taxOrderPostfix,
        bool $enablePaymentTaxSplit
    ) {
        $this->stripeRefundFactory = $stripeRefundFactory;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->taxOrderPostfix = $taxOrderPostfix;
        $this->enablePaymentTaxSplit = $enablePaymentTaxSplit;
    }

    /**
     * @return array App\Entity\StripeTransfer[]
     */
    public function getRetriableTransfers(): mixed
    {
        return $this->stripeTransferRepository->findRetriableRefundTransfers();
    }

    /**
     * Recreate missing shortfall reversals and return pending ones for dispatch.
     * Repeated calls are safe: each shortfall uses a stable Mirakl ID.
     *
     * @return array App\Entity\StripeTransfer[]
     */
    public function reconcileTaxShortfallReversals(): array
    {
        if (!$this->enablePaymentTaxSplit) {
            return [];
        }

        $transfers = $this->stripeTransferRepository->findRefundTransfersForTaxShortfallReconciliation();
        $transfersByMiraklId = [];
        foreach ($transfers as $transfer) {
            $transfersByMiraklId[$transfer->getMiraklId()] = $transfer;
        }

        $pendingShortfalls = [];
        $shortfallSuffix = $this->taxOrderPostfix.'_SHORTFALL';
        foreach ($transfers as $transfer) {
            $miraklId = $transfer->getMiraklId();
            if (StripeTransfer::TRANSFER_PENDING === $transfer->getStatus()
                && str_ends_with($miraklId, $shortfallSuffix)) {
                $pendingShortfalls[$miraklId] = $transfer;
                continue;
            }

            if (StripeTransfer::TRANSFER_CREATED !== $transfer->getStatus()
                || !str_ends_with($miraklId, $this->taxOrderPostfix)
                || str_ends_with($miraklId, $shortfallSuffix)) {
                continue;
            }

            $refundId = substr($miraklId, 0, -strlen($this->taxOrderPostfix));
            $sellerReversal = $transfersByMiraklId[$refundId] ?? null;
            if (!$sellerReversal || StripeTransfer::TRANSFER_CREATED !== $sellerReversal->getStatus()) {
                continue;
            }

            $shortfall = $this->stripeTransferFactory->createTaxShortfallReversal(
                $refundId,
                $sellerReversal
            );
            if ($shortfall && $shortfall->isDispatchable()) {
                $pendingShortfalls[$shortfall->getMiraklId()] = $shortfall;
            }
        }

        if ($pendingShortfalls) {
            $this->stripeTransferRepository->flush();
        }

        return array_values($pendingShortfalls);
    }

    /**
     * @return array App\Entity\StripeRefund[]
     */
    public function getRefundsFromOrderRefunds(array $orderRefunds): array
    {
        // Retrieve existing StripeRefunds with provided refund IDs
        $existingRefunds = $this->stripeRefundRepository->findRefundsByRefundIds(
            array_keys($orderRefunds)
        );

        $refunds = [];
        foreach ($orderRefunds as $refundId => $orderRefund) {
            if (is_array($existingRefunds) && isset($existingRefunds[$refundId])) {
                $refund = $existingRefunds[$refundId];
                if (!$refund->isRetriable()) {
                    continue;
                }

                $refund = $this->stripeRefundFactory->updateRefund($refund, $orderRefund);
            } else {
                // Create new refund
                $refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund);

                $this->stripeRefundRepository->persist($refund);
            }

            $refunds[] = $refund;
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $refunds;
    }

    /**
     * @return array App\Entity\StripeRefund[]
     */
    public function getTransfersFromOrderRefunds(array $orderRefunds): array
    {
        $transfers = [];
        foreach ($orderRefunds as $refundId => $orderRefund) {
            $components = [[$refundId, false]];
            $transferIds = [$refundId];
            $orderIds = [$orderRefund->getOrderId()];
            if ($this->enablePaymentTaxSplit) {
                $taxRefundId = $refundId.$this->taxOrderPostfix;
                $components[] = [$taxRefundId, true];
                $transferIds[] = $taxRefundId;
                $orderIds[] = $orderRefund->getOrderId().$this->taxOrderPostfix;
            }

            $refundTransfers = $this->stripeTransferRepository->wrapInTransaction(
                function () use ($orderRefund, $components, $transferIds, $orderIds): array {
                    // Serialize capacity checks for reversals against the same
                    // original transfers, then commit their reservations together.
                    $this->stripeTransferRepository->lockOrderTransfersByMiraklIds($orderIds);
                    $existingTransfers = $this->stripeTransferRepository->findTransfersByRefundIds($transferIds);

                    $refundTransfers = [];
                    foreach ($components as [$transferId, $isForTax]) {
                        if (isset($existingTransfers[$transferId])) {
                            $transfer = $existingTransfers[$transferId];
                            if (!$transfer->isRetriable()) {
                                continue;
                            }

                            $transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer, $isForTax);
                        } else {
                            $transfer = $isForTax
                                ? $this->stripeTransferFactory->createFromOrderRefundForTax($orderRefund)
                                : $this->stripeTransferFactory->createFromOrderRefund($orderRefund);
                            $this->stripeTransferRepository->persist($transfer);
                        }

                        $refundTransfers[] = $transfer;
                    }

                    if ($this->enablePaymentTaxSplit && isset($existingTransfers[$orderRefund->getId()])) {
                        $shortfallReversal = $this->stripeTransferFactory->createTaxShortfallReversal(
                            $orderRefund->getId(),
                            $existingTransfers[$orderRefund->getId()]
                        );
                        if ($shortfallReversal) {
                            $refundTransfers[] = $shortfallReversal;
                        }
                    }

                    return $refundTransfers;
                }
            );
            $transfers = array_merge($transfers, $refundTransfers);
        }

        return $transfers;
    }

    /**
     * @return array [ refund_id => App\Entity\StripeTransfer ]
     */
    public function updateTransfers(array $transfers): array
    {
        $updated = [];
        foreach ($transfers as $refundId => $transfer) {
            if (str_ends_with($refundId, $this->taxOrderPostfix.'_SHORTFALL')) {
                $updated[$refundId] = $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
            } elseif (false !== strpos($refundId, $this->taxOrderPostfix)) {
                $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer, true);
                $baseRefundId = substr($refundId, 0, -strlen($this->taxOrderPostfix));
                $sellerReversals = $this->stripeTransferRepository->findTransfersByRefundIds([$baseRefundId]);
                if (isset($sellerReversals[$baseRefundId])) {
                    $shortfallReversal = $this->stripeTransferFactory->createTaxShortfallReversal(
                        $baseRefundId,
                        $sellerReversals[$baseRefundId]
                    );
                    if ($shortfallReversal) {
                        $updated[$shortfallReversal->getMiraklId()] = $shortfallReversal;
                    }
                }
            } else {
                $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
            }
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $updated;
    }
}
