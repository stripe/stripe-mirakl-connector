<?php

namespace App\Service;

use App\Entity\MiraklServiceOrder;
use App\Service\MiraklClient;
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

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    public function __construct(
        StripeTransferFactory $stripeTransferFactory,
        StripeTransferRepository $stripeTransferRepository,
        MiraklClient $miraklClient
    ) {
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklClient = $miraklClient;
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
    public function getTransfersFromOrders(array $orders): array
    {
        // Retrieve existing StripeTransfers with provided order IDs
        $existingTransfers = $this->stripeTransferRepository
            ->findTransfersByOrderIds(array_keys($orders));
        $pendingDebits = $this->getServiceOrdersPendingDebits($orders);

        $transfers = [];
        foreach ($orders as $orderId => $order) {
            if (isset($existingTransfers[$orderId])) {
                $transfer = $existingTransfers[$orderId];
                if (!$transfer->isRetriable()) {
                    continue;
                }

                // Use existing transfer
                $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order, $pendingDebits);
            } else {
                // Create new transfer
                $transfer = $this->stripeTransferFactory->createFromOrder($order);
                $this->stripeTransferRepository->persist($transfer);
            }

            $transfers[] = $transfer;
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
    public function updateTransfersFromOrders(array $existingTransfers, array $orders)
    {
        $pendingDebits = $this->getServiceOrdersPendingDebits($orders);
        $updated = [];
        foreach ($existingTransfers as $orderId => $transfer) {
            $updated[$orderId] = $this->stripeTransferFactory->updateFromOrder(
                $transfer,
                $orders[$orderId],
                $pendingDebits
            );
        }

        // Save
        $this->stripeTransferRepository->flush();

        return $updated;
    }

    private function getServiceOrdersPendingDebits(array $orders): array
    {
        $serviceOrders = array_filter($orders, function ($order) {
            return is_a($order, MiraklServiceOrder::class);
        });
        $serviceOrderIds = array_map(function ($order) {
            return $order->getId();
        }, $serviceOrders);
        return $this->miraklClient->listServicePendingDebitsByOrderIds($serviceOrderIds);
    }
}
