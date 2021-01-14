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

    public function __construct(
        StripeRefundFactory $stripeRefundFactory,
        StripeTransferFactory $stripeTransferFactory,
        StripeRefundRepository $stripeRefundRepository,
        StripeTransferRepository $stripeTransferRepository
    ) {
        $this->stripeRefundFactory = $stripeRefundFactory;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
    }

    /**
     * @return array App\Entity\StripeTransfer[]
     */
    public function getRetriableTransfers(): array
    {
        return $this->stripeTransferRepository->findRetriableRefundTransfers();
    }

    /**
     * @param array $orders
     * @return array App\Entity\StripeRefund[]
     */
    public function getRefundsFromOrderRefunds(array $orders): array
    {
        // Retrieve existing StripeRefunds with provided refund IDs
        $refundIds = $this->getRefundIdsFromOrders($orders);
        $existingRefunds = $this->stripeRefundRepository->findRefundsByRefundIds($refundIds);

        $refunds = [];
        foreach ($orders as $order) {
            foreach ($order['order_lines']['order_line'] as $i => $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $j => $orderRefund) {
                    if (isset($existingRefunds[$orderRefund['id']])) {
                        $refund = $existingRefunds[$orderRefund['id']];
                        if (!$refund->isRetriable()) {
                            continue;
                        }

                        $refund = $this->stripeRefundFactory->updateRefund($refund);
                    } else {
                        // Create new refund
                        $refund = $this->stripeRefundFactory->createFromOrderRefund($order, $i, $j);

                        $this->stripeRefundRepository->persist($refund);
                    }

                    $refunds[] = $refund;
                }
            }
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $refunds;
    }

    /**
     * @param array $orders
     * @return array App\Entity\StripeRefund[]
     */
    public function getTransfersFromOrderRefunds(array $orders): array
    {
        // Retrieve existing StripeTransfers with provided refund IDs
        $refundIds = $this->getRefundIdsFromOrders($orders);
        $existingTransfers = $this->stripeTransferRepository->findTransfersByRefundIds($refundIds);

        $transfers = [];
        foreach ($orders as $order) {
            foreach ($order['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $orderRefund) {
                    if (isset($existingTransfers[$orderRefund['id']])) {
                        $transfer = $existingTransfers[$orderRefund['id']];
                        if (!$transfer->isRetriable()) {
                            continue;
                        }

                        $transfer = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
                    } else {
                        // Create new transfer
                        $transfer = $this->stripeTransferFactory->createFromOrderRefund($orderRefund);

                        $this->stripeTransferRepository->persist($transfer);
                    }

                    $transfers[] = $transfer;
                }
            }
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $transfers;
    }

    /**
     * @param array $transfers
     * @return array [ refund_id => App\Entity\StripeTransfer ]
     */
    public function updateTransfers(array $transfers)
    {
        $updated = [];
        foreach ($transfers as $refundId => $transfer) {
            $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $updated;
    }

    /**
     * @param array $orders
     * @return array string[]
     */
    private function getRefundIdsFromOrders(array $orders)
    {
        $refundIds = [];
        foreach ($orders as $order) {
            foreach ($order['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $orderRefund) {
                    $refundIds[] = (string) $orderRefund['id'];
                }
            }
        }

        return $refundIds;
    }
}
