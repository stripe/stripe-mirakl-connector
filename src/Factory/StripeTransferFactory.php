<?php

namespace App\Factory;

use App\Entity\AccountMapping;
use App\Entity\MiraklOrder;
use App\Entity\MiraklPendingDebit;
use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklProductOrder;
use App\Entity\MiraklServiceOrder;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Exception\InvalidArgumentException;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Charge;
use Stripe\Exception\InvalidRequestException;

class StripeTransferFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

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

    private $stripeTaxAccount;
    private $taxOrderPostfix;
    private $enablePaymentTaxSplit;

    /**
     * @var bool
     */
    private $enableSubtractTaxesFromTransferAmount;

    public function __construct(
        AccountMappingRepository $accountMappingRepository,
        PaymentMappingRepository $paymentMappingRepository,
        StripeRefundRepository $stripeRefundRepository,
        StripeTransferRepository $stripeTransferRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        string $stripeTaxAccount,
        string $taxOrderPostfix,
        bool $enablePaymentTaxSplit,
        bool $enableSubtractTaxesFromTransferAmount
    ) {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->stripeTaxAccount = $stripeTaxAccount;
        $this->taxOrderPostfix = $taxOrderPostfix;
        $this->enablePaymentTaxSplit = $enablePaymentTaxSplit;
        $this->enableSubtractTaxesFromTransferAmount = $enableSubtractTaxesFromTransferAmount;
    }

    public function createFromOrder(MiraklOrder $order, MiraklPendingDebit $pendingDebit = null): StripeTransfer
    {
        if (is_a($order, MiraklServiceOrder::class)) {
            $type = StripeTransfer::TRANSFER_SERVICE_ORDER;
        } else {
            $type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
        }

        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setMiraklId($order->getId());
        $transfer->setMiraklCreatedDate($order->getCreationDateAsDateTime());

        return $this->updateFromOrder($transfer, $order, $pendingDebit);
    }

    public function createFromOrderForTax(MiraklOrder $order, MiraklPendingDebit $pendingDebit = null): StripeTransfer
    {
        if (is_a($order, MiraklServiceOrder::class)) {
            $type = StripeTransfer::TRANSFER_SERVICE_ORDER;
        } else {
            $type = StripeTransfer::TRANSFER_PRODUCT_ORDER;
        }

        $transfer = new StripeTransfer();
        $transfer->setType($type);
        $transfer->setMiraklId($order->getId().$this->taxOrderPostfix);
        $transfer->setMiraklCreatedDate($order->getCreationDateAsDateTime());

        return $this->updateFromOrder($transfer, $order, $pendingDebit, true);
    }

    public function updateFromOrder(StripeTransfer $transfer, MiraklOrder $order, MiraklPendingDebit $pendingDebit = null, bool $isForTax = false): StripeTransfer
    {
        // Transfer already created
        if ($transfer->getTransferId()) {
            return $this->markTransferAsCreated($transfer);
        }

        // Shop must have a Stripe account
        try {
            // account mapping for the original shop
            $shop_accountMapping = $this->getAccountMapping($order->getShopId());

            if ($isForTax) {
                $taxAccountMapping = $this->getAccountMappingByAccountId($this->stripeTaxAccount);
            }

            if (!$isForTax) {
                $accountMapping = $shop_accountMapping;
            } else {
                $accountMapping = $taxAccountMapping;
            }
            $transfer->setAccountMapping($accountMapping);
        } catch (InvalidArgumentException $e) {
            // Onboarding still to be completed, let's wait
            return $this->putTransferOnHold($transfer, $e->getMessage());
        }

        if ($shop_accountMapping->getIgnored()) {
            $shopId = $shop_accountMapping->getMiraklShopId();

            return $this->ignoreTransfer($transfer, "Shop $shopId is ignored");
        }

        // Order must not be refused or canceled
        if ($order->isAborted()) {
            return $this->abortTransfer(
                $transfer,
                sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_ABORTED, $order->getState())
            );
        }

        // Order must be fully accepted
        if (!$order->isValidated()) {
            return $this->putTransferOnHold(
                $transfer,
                sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_NOT_READY, $order->getState())
            );
        }

        // Order must be fully paid
        $paymentId = null;
        if (is_a($order, MiraklProductOrder::class)) {
            if (!$order->isPaid()) {
                return $this->putTransferOnHold(
                    $transfer,
                    sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_NOT_READY, $order->getState())
                );
            }

            $paymentId = $order->getTransactionNumber();
        } elseif (is_a($order, MiraklServiceOrder::class)) {
            if (!$pendingDebit || !$pendingDebit->isPaid()) {
                return $this->putTransferOnHold(
                    $transfer,
                    sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_NOT_READY, $order->getState())
                );
            }

            $paymentId = $pendingDebit->getTransactionNumber();
        }

        // Save charge ID to be used in source_transaction
        if (!$transfer->getTransactionId()) {
            try {
                if (!$paymentId) {
                    // Check for a payment mapping
                    $paymentMapping = current($this->paymentMappingRepository->findPaymentsByCommercialOrderIds(
                        [$order->getCommercialId()]
                    ));

                    if ($paymentMapping && $paymentMapping->getStripeChargeId()) {
                        $transfer->setTransactionId(
                            $this->getSourceTransactionId($paymentMapping->getStripeChargeId())
                        );
                    }
                } else {
                    $transfer->setTransactionId(
                        $this->getSourceTransactionId($paymentId)
                    );
                }
            } catch (InvalidRequestException $e) {
                return $this->abortTransfer(
                    $transfer,
                    $e->getMessage()
                );
            } catch (InvalidArgumentException $e) {
                switch ($e->getCode()) {
                    // Problem is final, let's abort
                    case 10:
                        return $this->abortTransfer($transfer, $e->getMessage());
                        // Problem is just temporary, let's put on hold
                    case 20:
                        return $this->putTransferOnHold($transfer, $e->getMessage());
                }
            }
        }

        // Amount and currency
        $amount = $order->getAmountDue();
        $commission = $order->getOperatorCommission();
        $transferAmount = $amount - $commission;

        if ($this->enablePaymentTaxSplit) {
            $orderTaxTotal = $order->getOrderTaxTotal();
            if ($isForTax) {
                $transferAmount = $orderTaxTotal;
            } else {
                $transferAmount = $transferAmount - $orderTaxTotal;
            }
        } elseif ($this->enableSubtractTaxesFromTransferAmount) {
            $orderTaxTotal = $order->getOrderTaxTotal();
            $transferAmount = $transferAmount - $orderTaxTotal;
        }

        if ($transferAmount <= 0) {
            return $this->abortTransfer($transfer, sprintf(
                StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT,
                $transferAmount
            ));
        }

        $transfer->setAmount(gmp_intval((string) ($transferAmount * 100)));
        $transfer->setCurrency(strtolower($order->getCurrency()));

        // All good
        return $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
    }

    public function createFromOrderRefund(MiraklPendingRefund $orderRefund): StripeTransfer
    {
        $transfer = new StripeTransfer();
        $transfer->setType(StripeTransfer::TRANSFER_REFUND);
        $transfer->setMiraklId($orderRefund->getId());

        return $this->updateOrderRefundTransfer($transfer);
    }

    public function createFromOrderRefundForTax(MiraklPendingRefund $orderRefund): StripeTransfer
    {
        $transfer = new StripeTransfer();
        $transfer->setType(StripeTransfer::TRANSFER_REFUND);
        $transfer->setMiraklId($orderRefund->getId().$this->taxOrderPostfix);

        return $this->updateOrderRefundTransfer($transfer, true);
    }

    public function updateOrderRefundTransfer(StripeTransfer $transfer, bool $isForTax = false): StripeTransfer
    {
        // Transfer already reversed
        if ($transfer->getTransferId()) {
            return $this->markTransferAsCreated($transfer);
        }

        // Check corresponding StripeRefund
        $refund = null;
        try {
            $refund = $this->findRefundFromRefundId(str_replace($this->taxOrderPostfix, '', $transfer->getMiraklId()));

            // Fetch transfer to be reversed
            $orderIds = [$refund->getMiraklOrderId()];
            if ($isForTax) {
                $orderIds = [$refund->getMiraklOrderId().$this->taxOrderPostfix];
            }

            $orderTransfer = current($this->stripeTransferRepository->findTransfersByOrderIds($orderIds));

            // Check order transfer status
            if (!$orderTransfer || StripeTransfer::TRANSFER_CREATED !== $orderTransfer->getStatus()) {
                // TODO: abort if order transfer has been aborted
                return $this->putTransferOnHold(
                    $transfer,
                    StripeTransfer::TRANSFER_STATUS_REASON_TRANSFER_NOT_READY
                );
            }

            // Save transfer ID as the transaction to be reversed
            if (!$transfer->getTransactionId()) {
                $transfer->setTransactionId($orderTransfer->getTransferId());
            }

            // Fetch the order to calculate the right amount to reverse
            if (StripeTransfer::TRANSFER_PRODUCT_ORDER === $orderTransfer->getType()) {
                $order = current($this->miraklClient->listProductOrdersById($orderIds));
            } else {
                $order = current($this->miraklClient->listServiceOrdersById($orderIds));
            }

            // Amount and currency
            $commission = $order->getRefundedOperatorCommission($refund);
            $commission = gmp_intval((string) ($commission * 100));
            $transferAmount = $refund->getAmount() - $commission;
            if ($this->enablePaymentTaxSplit) {
                $refundedTax = $order->getRefundedTax($refund);
                $refundedTax = gmp_intval((string) ($refundedTax * 100));
                if ($isForTax) {
                    $transferAmount = $refundedTax;
                } else {
                    $transferAmount = $transferAmount - $refundedTax;
                }
            } elseif ($this->enableSubtractTaxesFromTransferAmount) {
                $orderTaxTotal = $order->getOrderTaxTotal() * 100;
                $transferAmount = $transferAmount - $orderTaxTotal;
            }
            $transfer->setAmount($transferAmount);
            $transfer->setCurrency(strtolower($order->getCurrency()));
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10:
                    return $this->abortTransfer($transfer, $e->getMessage());
                    // Problem is just temporary, let's put on hold
                case 20:
                    return $this->putTransferOnHold($transfer, $e->getMessage());
            }
        }

        // All good
        return $transfer->setStatus(StripeTransfer::TRANSFER_PENDING);
    }

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
                case 10:
                    return $this->abortTransfer($transfer, $e->getMessage());
                    // Problem is just temporary, let's put on hold
                case 20:
                    return $this->putTransferOnHold($transfer, $e->getMessage());
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

    private function getAccountMapping(int $shopId): AccountMapping
    {
        if (!$shopId) {
            throw new InvalidArgumentException(StripeTransfer::TRANSFER_STATUS_REASON_NO_SHOP_ID, 10);
        }

        $mapping = $this->accountMappingRepository->findOneBy([
            'miraklShopId' => $shopId,
        ]);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_SHOP_NOT_READY, $shopId), 20);
        }

        return $mapping;
    }

    private function getSourceTransactionId(string $trid): ?string
    {
        $transactionId = null;
        if (0 === strpos($trid, 'pi_')) {
            // Transaction number is a PaymentIntent
            $pi = $this->stripeClient->paymentIntentRetrieve($trid);
            switch ($pi->status) {
                case 'succeeded':
                    // Still have to check if it has been refunded
                    $ch = $pi->charges->data[0] ?? null;
                    if ($ch instanceof Charge) {
                        $transactionId = $this->getSourceTransactionIdFromCharge($ch);
                    }
                    break;
                case 'canceled':
                    throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_CANCELED, $trid), 10);
                default:
                    throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY, $trid, $pi->status), 20);
            }
        } elseif (0 === strpos($trid, 'ch_') || 0 === strpos($trid, 'py_')) {
            // Transaction number is a Charge
            $ch = $this->stripeClient->chargeRetrieve($trid);
            $transactionId = $this->getSourceTransactionIdFromCharge($ch);
        }

        return $transactionId;
    }

    private function getSourceTransactionIdFromCharge(Charge $ch): string
    {
        switch ($ch->status) {
            case 'succeeded':
                if (false === $ch->captured) {
                    throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY, $ch->id, $ch->status.' (not captured)'), 20);
                }

                if (true === $ch->refunded) {
                    throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_CANCELED, $ch->id), 10);
                }

                return $ch->id;
            case 'failed':
                throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_FAILED, $ch->id), 10);
            default:
                throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_PAYMENT_NOT_READY, $ch->id, $ch->status), 20);
        }
    }

    private function findRefundFromRefundId(string $refundId): StripeRefund
    {
        $refund = current($this->stripeRefundRepository->findRefundsByRefundIds(
            [$refundId]
        ));

        if (!$refund) {
            throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_REFUND_NOT_FOUND, $refundId), 20);
        }

        switch ($refund->getStatus()) {
            case StripeRefund::REFUND_CREATED:
                // Perfect
                return $refund;
            case StripeRefund::REFUND_ABORTED:
                throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ORDER_REFUND_ABORTED, $refundId), 10);
            default:
                throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_REFUND_NOT_VALIDATED, $refundId), 20);
        }
    }

    private function getInvoiceAmount(array $invoice, string $type): int
    {
        static $typeToKey = [
            StripeTransfer::TRANSFER_SUBSCRIPTION => 'total_subscription_incl_tax',
            StripeTransfer::TRANSFER_EXTRA_CREDITS => 'total_other_credits_incl_tax',
            StripeTransfer::TRANSFER_EXTRA_INVOICES => 'total_other_invoices_incl_tax',
        ];

        $amount = $invoice['summary'][$typeToKey[$type]] ?? 0;
        $amount = abs(gmp_intval((string) ($amount * 100)));
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_INVALID_AMOUNT, $amount));
        }

        return $amount;
    }

    private function putTransferOnHold(StripeTransfer $transfer, string $reason): StripeTransfer
    {
        $this->logger->info(
            'Transfer on hold: '.$reason,
            [
                'order_id' => $transfer->getMiraklId(),
                'transfer_id' => $transfer->getTransferId(),
                'transaction_id' => $transfer->getTransactionId(),
                'status_reason' => $transfer->getStatusReason(),
                'status' => $transfer->getStatus(),
                'amount' => $transfer->getAmount()
            ]
        );

        return $transfer
            ->setStatus(StripeTransfer::TRANSFER_ON_HOLD)
            ->setStatusReason(substr($reason, 0, 1024));
    }

    private function abortTransfer(StripeTransfer $transfer, string $reason): StripeTransfer
    {
        $this->logger->info(
              'Transfer aborted: '.$reason,
            [
                'order_id' => $transfer->getMiraklId(),
                'transfer_id' => $transfer->getTransferId(),
                'transaction_id' => $transfer->getTransactionId(),
                'status_reason' => $transfer->getStatusReason(),
                'status' => $transfer->getStatus(),
                'amount' => $transfer->getAmount(),
            ]
        );

        return $transfer
            ->setStatus(StripeTransfer::TRANSFER_ABORTED)
            ->setStatusReason(substr($reason, 0, 1024));
    }

    private function ignoreTransfer(StripeTransfer $transfer, string $reason): StripeTransfer
    {
        $this->logger->info(
              'Transfer ignored: '.$reason,
            [
                'order_id' => $transfer->getMiraklId(),
                'transfer_id' => $transfer->getTransferId(),
                'transaction_id' => $transfer->getTransactionId(),
                'status_reason' => $transfer->getStatusReason(),
                'status' => $transfer->getStatus(),
                'amount' => $transfer->getAmount(),
            ]
        );

        return $transfer
            ->setStatus(StripeTransfer::TRANSFER_IGNORED)
            ->setStatusReason(substr($reason, 0, 1024));
    }

    private function markTransferAsCreated(StripeTransfer $transfer): StripeTransfer
    {
        $this->logger->info(
            'Transfer created',
            ['order_id' => $transfer->getMiraklId()]
        );

        return $transfer
            ->setStatus(StripeTransfer::TRANSFER_CREATED)
            ->setStatusReason(null);
    }

    private function getAccountMappingByAccountId(string $stripeAccountId): AccountMapping
    {
        if (!$stripeAccountId) {
            throw new InvalidArgumentException(StripeTransfer::TRANSFER_STATUS_REASON_NO_ACCOUNT_ID, 10);
        }

        $mapping = $this->accountMappingRepository->findOneByStripeAccountId($stripeAccountId);

        if (!$mapping) {
            throw new InvalidArgumentException(sprintf(StripeTransfer::TRANSFER_STATUS_REASON_ACCOUNT_NOT_FOUND, $stripeAccountId), 20);
        }

        return $mapping;
    }
}
