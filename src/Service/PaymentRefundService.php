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
    public function getRefundsFromOrderRefunds(array $orders, string $orderType): array
    {
        // Retrieve existing StripeRefunds with provided refund IDs
        $orderRefunds = self::parseOrderRefunds($orders, $orderType);
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
                $refund = $this->stripeRefundFactory->createFromOrderRefund($orderRefund, $orderType);

                $this->stripeRefundRepository->persist($refund);
            }

            $refunds[] = $refund;
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $refunds;
    }

    /**
     * @param array $orders
     * @return array App\Entity\StripeRefund[]
     */
    public function getTransfersFromOrderRefunds(array $orders, string $orderType): array
    {
        // Retrieve existing StripeTransfers with provided refund IDs
        $orderRefunds = $this->parseOrderRefunds($orders, $orderType);
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
            } else {
                // Create new transfer
                $transfer = $this->stripeTransferFactory->createFromOrderRefund($orderRefund);

                $this->stripeTransferRepository->persist($transfer);
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
            $updated[$refundId] = $this->stripeTransferFactory->updateOrderRefundTransfer($transfer);
        }

        // Save
        $this->stripeRefundRepository->flush();

        return $updated;
    }

    /**
     * @param array $orders
     * @param string $orderType
     * @return array
     */
    public static function parseOrderRefunds(array $orders, string $orderType): array
    {
        $refunds = [];
        if (MiraklClient::ORDER_TYPE_PRODUCT === $orderType) {
            foreach ($orders as $order) {
                foreach ($order['order_lines']['order_line'] as $orderLine) {
                    foreach ($orderLine['refunds']['refund'] as $orderRefund) {
                        $orderRefund['currency_code'] = $order['currency_iso_code'];
                        $orderRefund['order_id'] = $order['order_id'];
                        $orderRefund['order_line_id'] = $orderLine['order_line_id'];
                        $refunds[$orderRefund['id']] = $orderRefund;
                    }
                }
            }
        } elseif (MiraklClient::ORDER_TYPE_SERVICE === $orderType) {
            foreach ($orders as $orderRefund) {
                $refunds[$orderRefund['id']] = $orderRefund;
            }
        }

        return $refunds;
    }
}
