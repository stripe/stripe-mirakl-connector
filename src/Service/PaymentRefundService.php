<?php

namespace App\Service;

use App\Service\MiraklClient;
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

    public function __construct(
        StripeRefundFactory $stripeRefundFactory,
        StripeTransferFactory $stripeTransferFactory,
        StripeRefundRepository $stripeRefundRepository,
        StripeTransferRepository $stripeTransferRepository,
        string $taxOrderPostfix
    ) {
        $this->stripeRefundFactory = $stripeRefundFactory;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->taxOrderPostfix = $taxOrderPostfix;
    }

    /**
     * @return array App\Entity\StripeTransfer[]
     */
    public function getRetriableTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableRefundTransfers();
    }

    /**
     * @param array $orderRefunds
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
            if (isset($existingRefunds[$refundId])) {
                $refund = $existingRefunds[$refundId];
                if (!$refund->isRetriable()) {
                    continue;
                }

                $refund = $this->stripeRefundFactory->updateRefund($refund);
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
     * @param array $orderRefunds
     * @return array App\Entity\StripeRefund[]
     */
    public function getTransfersFromOrderRefunds(array $orderRefunds): array
    {
        // Retrieve existing StripeTransfers with provided refund IDs
        $existingTransfers = $this->stripeTransferRepository->findTransfersByRefundIds(
            array_keys($orderRefunds)
        );

        $transfers = [];
        foreach ($orderRefunds as $refundId => $orderRefund) {
            if (isset($existingTransfers[$refundId])) {
                $transfer = $existingTransfers[$refundId];
                if (!$transfer->isRetriable()) {
                    continue;
                }

                $transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
                $transfer_tax = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer, true);
                $transfers[] = $transfer_tax;
            } else {
                // Create new transfer
                $transfer = $this->stripeTransferFactory->createFromOrderRefund($orderRefund);
                $transfer_tax = $this->stripeTransferFactory->createFromOrderRefundForTax($orderRefund);
                $this->stripeTransferRepository->persist($transfer);
                $this->stripeTransferRepository->persist($transfer_tax);
                $transfers[] = $transfer_tax;
            }

            $transfers[] = $transfer;
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfers;
    }

    /**
     * @param array $transfers
     * @return array [ refund_id => App\Entity\StripeTransfer ]
     */
    public function updateTransfers(array $transfers): array
    {
        $updated = [];
        foreach ($transfers as $refundId => $transfer) {
            if (strpos($refundId, $this->taxOrderPostfix) !== false) {
                $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer, true);
            } else {
                $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
            }
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $updated;
    }
}
