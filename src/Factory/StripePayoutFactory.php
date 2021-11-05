<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripePayout;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class StripePayoutFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    public function __construct(
        AccountMappingRepository $accountMappingRepository
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
    }

    /**
     * @param array $invoice
     * @return StripePayout
     */
    public function createFromInvoice(array $invoice): StripePayout
    {
        $payout = new StripePayout();
        $payout->setMiraklInvoiceId($invoice['invoice_id']);
        $payout->setMiraklCreatedDate(
            MiraklClient::getDatetimeFromString($invoice['date_created'])
        );

        return $this->updateFromInvoice($payout, $invoice);
    }

    /**
     * @param StripePayout $payout
     * @param array $invoice
     * @return StripePayout
     */
    public function updateFromInvoice(StripePayout $payout, array $invoice): StripePayout
    {
        // Payout already created
        if ($payout->getPayoutId()) {
            return $this->markPayoutAsCreated($payout);
        }

        // Amount and currency
        try {
            $payout->setAmount($this->getInvoiceAmount($invoice));
            $payout->setCurrency(strtolower($invoice['currency_iso_code']));
        } catch (InvalidArgumentException $e) {
            return $this->abortPayout($payout, $e->getMessage());
        }

        // Save Stripe account corresponding with this shop
        try {
            $payout->setAccountMapping(
                $this->getAccountMapping($invoice['shop_id'] ?? 0)
            );
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                    // Problem is final, let's abort
                case 10:
                    return $this->abortPayout($payout, $e->getMessage());
                    // Problem is just temporary, let's put on hold
                case 20:
                    return $this->putPayoutOnHold($payout, $e->getMessage());
            }
        }

        // All good
        return $payout->setStatus(StripePayout::PAYOUT_PENDING);
    }

    /**
     * @param int $shopId
     * @return AccountMapping
     */
    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            throw new InvalidArgumentException(
                StripePayout::PAYOUT_STATUS_REASON_NO_SHOP_ID,
                10
            );
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId
        ]);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(
                StripePayout::PAYOUT_STATUS_REASON_SHOP_NOT_READY,
                $shopId
            ), 20);
        }

        if (!$mapping->getPayoutEnabled()) {
            throw new InvalidArgumentException(sprintf(
                StripePayout::PAYOUT_STATUS_REASON_SHOP_PAYOUT_DISABLED,
                $shopId
            ), 20);
        }

        return $mapping;
    }

    /**
     * @param array $invoice
     * @return int
     */
    private function getInvoiceAmount(array $invoice): int
    {
        $amount = $invoice['summary']['amount_transferred'] ?? 0;
        $amount = gmp_intval((string) ($amount * 100));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                StripePayout::PAYOUT_STATUS_REASON_INVALID_AMOUNT,
                $amount
            ));
        }

        return $amount;
    }

    /**
     * @param StripePayout $payout
     * @param string $reason
     * @return StripePayout
     */
    private function putPayoutOnHold(StripePayout $payout, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout on hold: ' . $reason,
            ['invoice_id' => $payout->getMiraklInvoiceId()]
        );

        $payout->setStatusReason($reason);
        return $payout->setStatus(StripePayout::PAYOUT_ON_HOLD);
    }

    /**
     * @param StripePayout $payout
     * @param string $reason
     * @return StripePayout
     */
    private function abortPayout(StripePayout $payout, string $reason): StripePayout
    {
        $this->logger->info(
            'Payout aborted: ' . $reason,
            ['invoice_id' => $payout->getMiraklInvoiceId()]
        );

        $payout->setStatusReason($reason);
        return $payout->setStatus(StripePayout::PAYOUT_ABORTED);
    }

    /**
     * @param StripePayout $payout
     * @return StripePayout
     */
    private function markPayoutAsCreated(StripePayout $payout): StripePayout
    {
        $this->logger->info(
            'Payout created',
            ['invoice_id' => $payout->getMiraklInvoiceId()]
        );

        $payout->setStatusReason(null);
        return $payout->setStatus(StripePayout::PAYOUT_CREATED);
    }
}
