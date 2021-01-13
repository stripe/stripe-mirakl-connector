<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Charge;
use Stripe\Exception\ApiConnectionException;

class StripeTransferFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        StripeRefundRepository $stripeRefundRepository,
        StripeTransferRepository $stripeTransferRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
    }

    /**
     * @param array $order
     * @return StripeTransfer
     */
    public function createFromOrder(array $order): StripeTransfer
    {
        $transfer = new StripeTransfer();
        $transfer->setType(StripeTransfer::TRANSFER_ORDER);
        $transfer->setMiraklId($order['order_id']);

        try {
            $transfer->setMiraklCreatedDate(
                MiraklClient::getDatetimeFromString($order['created_date'])
            );
        } catch (InvalidArgumentException $e) {
            // Shouldn't happen, see MiraklClient::getDatetimeFromString
            return $this->abortTransfer($transfer, $e->getMessage());
        }

        return $this->updateFromOrder($transfer, $order);
    }

    /**
     * @param StripeTransfer $transfer
     * @param array $order
     * @return StripeTransfer
     */
    public function updateFromOrder(StripeTransfer $transfer, array $order): StripeTransfer
    {
        // Transfer already created
        if ($transfer->getTransferId()) {
            return $this->markTransferAsCreated($transfer);
        }

        // Save Stripe account corresponding with this shop
        try {
            $transfer->setAccountMapping(
                $this->getAccountMapping($order['shop_id'])
            );
        } catch (InvalidArgumentException $e) {
            // Onboarding still to be completed, let's wait
            return $this->putTransferOnHold($transfer, $e->getMessage());
        }

        // Make sure we are ready to process this order
        $ready = $this->checkOrderState($order['order_state']);
        if (-1 === $ready) {
            return $this->putTransferOnHold(
                $transfer,
                sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_NOT_READY, $order['order_state'])
            );
        } elseif (1 === $ready) {
            return $this->abortTransfer(
                $transfer,
                sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_ABORTED, $order['order_state'])
            );
        }

        // Amount and currency
        try {
            $transfer->setAmount($this->getOrderAmount($order));
            $transfer->setCurrency(strtolower($order['currency_iso_code']));
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10: return $this->abortTransfer($transfer, $e->getMessage());
                // Problem is just temporary, let's put on hold
                case 20: return $this->putTransferOnHold($transfer, $e->getMessage());
            }
        }

        // Save charge ID to be used in source_transaction
        $trid = $order['transaction_number'] ?? '';
        if (!$transfer->getTransactionId() && !empty($trid)) {
            try {
                $transfer->setTransactionId($this->getOrderTransactionId($trid));
            } catch (ApiConnectionException $e) {
                return $this->abortTransfer(
                    $transfer,
                    $e->getMessage()
                );
            } catch (InvalidArgumentException $e) {
                switch ($e->getCode()) {
                    // Problem is final, let's abort
                    case 10: return $this->abortTransfer($transfer, $e->getMessage());
                    // Problem is just temporary, let's put on hold
                    case 20: return $this->putTransferOnHold($transfer, $e->getMessage());
                }
            }
        }

        // All good
        return $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
    }

    /**
     * @param array $orderRefund
     * @return StripeTransfer
     */
    public function createFromOrderRefund(array $orderRefund): StripeTransfer
    {
        $transfer = new StripeTransfer();
        $transfer->setType(StripeTransfer::TRANSFER_REFUND);
        $transfer->setMiraklId($orderRefund['id']);

        return $this->updateOrderRefundTransfer($transfer);
    }

    /**
     * @param StripeTransfer $transfer
     * @return StripeTransfer
     */
    public function updateOrderRefundTransfer(StripeTransfer $transfer): StripeTransfer
    {
        // Transfer already reversed
        if ($transfer->getTransferId()) {
            return $this->markTransferAsCreated($transfer);
        }

        // Check corresponding StripeRefund
        $refund = null;
        try {
            $refund = $this->findRefundFromRefundId($transfer->getMiraklId());

            // Fetch transfer to be reversed
            if (!$transfer->getTransactionId()) {
                $orderTransfer = current($this->stripeTransferRepository
                                                    ->findTransfersByOrderIds([ $refund->getMiraklOrderId() ]));
                if (!$orderTransfer || StripeTransfer::TRANSFER_CREATED !== $orderTransfer->getStatus()) {
                    return $this->putTransferOnHold(
                        $transfer,
                        StripeTransfer::TRANSFER_STATUS_REASON_TRANSFER_NOT_READY
                    );
                }

                $transfer->setTransactionId($orderTransfer->getTransferId());
            }

            // Amount and currency
            $order = current($this->miraklClient->listOrdersById([
                $refund->getMiraklOrderId()
            ]));
            $transfer->setAmount($this->getOrderRefundAmount($order, $refund));
            $transfer->setCurrency(strtolower($order['currency_iso_code']));
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10: return $this->abortTransfer($transfer, $e->getMessage());
                // Problem is just temporary, let's put on hold
                case 20: return $this->putTransferOnHold($transfer, $e->getMessage());
            }
        }

        // All good
        return $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
    }

    /**
     * @param array $invoice
     * @param string $type
     * @return StripeTransfer
     */
    public function createFromInvoice(array $invoice, string $type): StripeTransfer
    {
        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setMiraklId($invoice['invoice_id']);

        try {
            $transfer->setMiraklCreatedDate(
                MiraklClient::getDatetimeFromString($invoice['date_created'])
            );
        } catch (InvalidArgumentException $e) {
            // Shouldn't happen, see MiraklClient::getDatetimeFromString
            return $this->abortTransfer($transfer, $e->getMessage());
        }

        return $this->updateFromInvoice($transfer, $invoice, $type);
    }

    /**
     * @param StripeTransfer $transfer
     * @param array $invoice
     * @param string $type
     * @return StripeTransfer
     */
    public function updateFromInvoice(StripeTransfer $transfer, array $invoice, string $type): StripeTransfer
    {
        // Transfer already created
        if ($transfer->getTransferId()) {
            return $this->markTransferAsCreated($transfer);
        }

        // Save Stripe account corresponding with this shop
        try {
            $transfer->setAccountMapping($this->getAccountMapping($invoice['shop_id'] ?? 0));
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10: return $this->abortTransfer($transfer, $e->getMessage());
                // Problem is just temporary, let's put on hold
                case 20: return $this->putTransferOnHold($transfer, $e->getMessage());
            }
        }

        // Amount and currency
        try {
            $transfer->setAmount($this->getInvoiceAmount($invoice, $type));
            $transfer->setCurrency(strtolower($invoice['currency_iso_code']));
        } catch (InvalidArgumentException $e) {
            return $this->abortTransfer($transfer, $e->getMessage());
        }

        // All good
        return $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
    }

    /**
     * @param int $shopId
     * @return AccountMapping
     */
    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            throw new InvalidArgumentException(
                StripeTransfer::TRANSFER_STATUS_REASON_NO_SHOP_ID,
                10
            );
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(
                StripeTransfer::TRANSFER_STATUS_REASON_SHOP_NOT_READY,
                $shopId
            ), 20);
        }

        return $mapping;
    }

    /**
     * @param string $state
     * @return int -1 = too soon / 0 = OK / +1 = too late
     */
    private function checkOrderState(string $state)
    {
        static $tooSoon = [
            'STAGING', 'WAITING_ACCEPTANCE', // Not scored or approved yet
            'WAITING_DEBIT', 'WAITING_DEBIT_PAYMENT' // Payment not processed yet
        ];

        static $tooLate = [
            'REFUSED', 'CANCELED'
        ];

        if (in_array($state, $tooSoon)) {
            return -1;
        }

        if (in_array($state, $tooLate)) {
            return 1;
        }

        return 0;
    }

    /**
     * @param array $order
     * @return int
     */
    private function getOrderAmount(array $order): int
    {
        $taxes = 0;
        $orderLines = $order['order_lines'] ?? [];
        foreach ($orderLines as $orderLine) {
            $ready = $this->checkOrderState($orderLine['order_line_state']);
            if (-1 === $ready) {
                throw new InvalidArgumentException(sprintf(
                    StripeTransfer::TRANSFER_STATUS_REASON_LINE_ITEM_NOT_READY,
                    $orderLine['order_line_state']
                ), 20);
            } elseif (1 === $ready) {
                continue;
            }

            $allTaxes = array_merge(
                $orderLine['shipping_taxes'] ?? [],
                $orderLine['taxes'] ?? []
            );

            foreach ($allTaxes as $tax) {
                $taxes += (float) $tax['amount'];
            }
        }

        $amount = $order['total_price'] + $taxes - $order['total_commission'];
        $amount = gmp_intval((string) ($amount * 100));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT,
                $amount
            ), 10);
        }

        return $amount;
    }

    /**
     * @param string $trid
     * @return string|null
     */
    private function getOrderTransactionId(string $trid): ?string
    {
        // Transaction number is a PaymentIntent
        if (0 === strpos($trid, 'pi_')) {
            $pi = $this->stripeClient->paymentIntentRetrieve($trid);
            switch ($pi->status) {
                case 'succeeded':
                    // Still have to check if it has been refunded
                    $ch = $pi->charges->data[0] ?? null;
                    if ($ch instanceof Charge) {
                        return $this->getOrderTransactionIdFromCharge($ch);
                    }

                    // Should not happen
                    throw new InvalidArgumentException(sprintf(
                        StripeTransfer::TRANSFER_STATUS_REASON_PAID_NO_CHARGE,
                        $trid
                    ), 20);
                case 'canceled':
                    throw new InvalidArgumentException(sprintf(
                        StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_CANCELED,
                        $trid
                    ), 10);
                default:
                    throw new InvalidArgumentException(sprintf(
                        StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY,
                        $trid,
                        $pi->status
                    ), 20);
            }
        }

        // Transaction number is a Charge
        if (0 === strpos($trid, 'ch_') || 0 === strpos($trid, 'py_')) {
            $ch = $this->stripeClient->chargeRetrieve($trid);
            return $this->getOrderTransactionIdFromCharge($ch);
        }

        return null;
    }

    /**
     * @param Charge $ch
     * @return string
     */
    private function getOrderTransactionIdFromCharge(Charge $ch): string
    {
        switch ($ch->status) {
            case 'succeeded':
                  if (false === $ch->captured) {
                      throw new InvalidArgumentException(sprintf(
                          StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY,
                          $ch->id,
                          $ch->status . ' (not captured)'
                      ), 20);
                  }

                  if (true === $ch->refunded) {
                      throw new InvalidArgumentException(sprintf(
                          StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_CANCELED,
                          $ch->id
                      ), 10);
                  }

                  return $ch->id;
            case 'failed':
                  throw new InvalidArgumentException(sprintf(
                      StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_FAILED,
                      $ch->id
                  ), 10);
            default:
                  throw new InvalidArgumentException(sprintf(
                      StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY,
                      $ch->id,
                      $ch->status
                  ), 20);
        }
    }

    /**
     * @param string $refundId
     * @return StripeRefund
     */
    private function findRefundFromRefundId(string $refundId): StripeRefund
    {
        $refund = current($this->stripeRefundRepository->findRefundsByRefundIds([
                        $refundId
                ]));

        if (!$refund) {
            throw new InvalidArgumentException(sprintf(
                StripeTransfer::TRANSFER_STATUS_REASON_REFUND_NOT_FOUND,
                $refundId
            ), 20);
        }

        switch ($refund->getStatus()) {
                        case StripeRefund::REFUND_CREATED:
                                // Perfect
                                return $refund;
                        case StripeRefund::REFUND_ABORTED:
                                throw new InvalidArgumentException(sprintf(
                                    StripeTransfer::TRANSFER_STATUS_REASON_ORDER_REFUND_ABORTED,
                                    $refundId
                                ), 10);
                        default:
                                throw new InvalidArgumentException(sprintf(
                                    StripeTransfer::TRANSFER_STATUS_REASON_REFUND_NOT_VALIDATED,
                                    $refundId
                                ), 20);
                }
    }

    /**
     * @param array $order
     * @param StripeRefund $refund
     * @return int
     */
    private function getOrderRefundAmount(array $order, StripeRefund $refund): int
    {
        $orderLineId = $refund->getMiraklOrderLineId();
        $refundId = $refund->getMiraklRefundId();

        foreach ($order['order_lines'] as $orderLine) {
            if ($orderLineId !== $orderLine['order_line_id']) {
                continue;
            }

            foreach ($orderLine['refunds'] as $orderRefund) {
                if ($refundId !== $orderRefund['id']) {
                    continue;
                }

                $amount = $orderRefund['amount'] - $orderRefund['commission_total_amount'];
                $amount = gmp_intval((string) ($amount * 100));

                if ($amount <= 0) {
                    throw new InvalidArgumentException(sprintf(
                        StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT,
                        $amount
                    ), 10);
                }

                return $amount;
            }
        }

        throw new InvalidArgumentException(sprintf(
            StripeTransfer::TRANSFER_STATUS_REASON_ORDER_REFUND_AMOUNT_NOT_FOUND,
            $orderLineId,
            $refundId
        ), 10);
    }

    /**
     * @param array $invoice
     * @param string $type
     * @return int
     */
    private function getInvoiceAmount(array $invoice, string $type): int
    {
        static $typeToKey = [
                        StripeTransfer::TRANSFER_SUBSCRIPTION => 'total_subscription_incl_tax',
                        StripeTransfer::TRANSFER_EXTRA_CREDITS => 'total_other_credits_incl_tax',
                        StripeTransfer::TRANSFER_EXTRA_INVOICES => 'total_other_invoices_incl_tax'
                ];

        $amount = $invoice['summary'][$typeToKey[$type]] ?? 0;
        $amount = gmp_intval((string) ($amount * 100));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT,
                $amount
            ));
        }

        return $amount;
    }

    /**
     * @param StripeTransfer $transfer
     * @param string $reason
     * @return StripeTransfer
     */
    private function putTransferOnHold(StripeTransfer $transfer, string $reason): StripeTransfer
    {
        $this->logger->info(
            'Transfer on hold: ' . $reason,
            [ 'order_id' => $transfer->getMiraklId() ]
        );

        return $transfer
                        ->setStatus(StripeTransfer::TRANSFER_ON_HOLD)
                        ->setStatusReason(substr($reason, 0, 1024));
    }

    /**
     * @param StripeTransfer $transfer
     * @param string $reason
     * @return StripeTransfer
     */
    private function abortTransfer(StripeTransfer $transfer, string $reason): StripeTransfer
    {
        $this->logger->info(
            'Transfer aborted: ' . $reason,
            [ 'order_id' => $transfer->getMiraklId() ]
        );

        return $transfer
                        ->setStatus(StripeTransfer::TRANSFER_ABORTED)
                        ->setStatusReason(substr($reason, 0, 1024));
    }

    /**
     * @param StripeTransfer $transfer
     * @return StripeTransfer
     */
    private function markTransferAsCreated(StripeTransfer $transfer): StripeTransfer
    {
        $this->logger->info(
            'Transfer created',
            [ 'order_id' => $transfer->getMiraklId() ]
        );

        return $transfer
                        ->setStatus(StripeTransfer::TRANSFER_CREATED)
                        ->setStatusReason(null);
    }
}
