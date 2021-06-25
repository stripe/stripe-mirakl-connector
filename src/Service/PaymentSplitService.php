<?php

namespace App\Service;

use App\Helper\LoggerHelper;
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
     * @var LoggerHelper
     */
    private $loggerHelper;

    public function __construct(
        StripeTransferFactory $stripeTransferFactory,
        StripeTransferRepository $stripeTransferRepository,
        LoggerHelper $loggerHelper
    ) {
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->loggerHelper = $loggerHelper;
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

        $transfers = [];
        foreach ($orders as $orderId => $order) {
            if (isset($existingTransfers[$orderId])) {
                $transfer = $existingTransfers[$orderId];
                if (!$transfer->isRetriable()) {
                    continue;
                }

                // Use existing transfer
                $transfer = $this->stripeTransferFactory->updateFromOrder($transfer, $order);
            } else {
                // Create new transfer
                $transfer = $this->stripeTransferFactory->createFromOrder($order);
                $this->stripeTransferRepository->persist($transfer);
            }

            $transfers[] = $transfer;
        }

        // Save
        try{
            $this->stripeTransferRepository->flush();
        } catch (\Throwable $exception){
            $this->loggerHelper->getLogger()->error($exception->getMessage(), []);
        }
        return $transfers;
    }

    /**
     * @param array $existingTransfers
     * @param array $orders
     * @return array App\Entity\StripeTransfer[]
     */
    public function updateTransfersFromOrders(array $existingTransfers, array $orders)
    {
        $updated = [];
        foreach ($existingTransfers as $orderId => $transfer) {
            $updated[$orderId] = $this->stripeTransferFactory->updateFromOrder(
                $transfer,
                $orders[$orderId]
            );
        }

        // Save
        try {
            $this->stripeTransferRepository->flush();
        } catch (\Throwable $exception){
            $this->loggerHelper->getLogger()->error($exception->getMessage(), []);
        }

        return $updated;
    }
}
