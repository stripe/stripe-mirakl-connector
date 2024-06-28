<?php

namespace App\Factory;

use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\StripeRefund;
use App\Exception\InvalidArgumentException;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Charge;
use Stripe\Exception\InvalidRequestException;

class StripeRefundFactory implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var bool
     */
    private $processRefundsWithoutOriginalTransaction;

    public function __construct(
        PaymentMappingRepository $paymentMappingRepository,
        StripeTransferRepository $stripeTransferRepository,
        StripeClient $stripeClient,
        $processRefundsWithoutOriginalTransaction
    ) {
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripeClient = $stripeClient;
        $this->processRefundsWithoutOriginalTransaction = $processRefundsWithoutOriginalTransaction;
    }

    public function createFromOrderRefund(MiraklPendingRefund $pendingRefund): StripeRefund
    {
        if (is_a($pendingRefund, MiraklServicePendingRefund::class)) {
            $type = StripeRefund::REFUND_SERVICE_ORDER;
        } else {
            $type = StripeRefund::REFUND_PRODUCT_ORDER;
        }

        $refund = new StripeRefund();
        $refund->setType($type);
        $refund->setMiraklCommercialOrderId($pendingRefund->getCommercialId());
        $refund->setMiraklOrderId($pendingRefund->getOrderId());
        $refund->setMiraklOrderLineId($pendingRefund->getOrderLineId());
        $refund->setAmount(gmp_intval((string) ($pendingRefund->getAmount() * 100)));
        $refund->setCurrency(strtolower($pendingRefund->getCurrency()));
        $refund->setMiraklRefundId($pendingRefund->getId());

        return $this->updateRefund($refund);
    }

    public function updateRefund(StripeRefund $refund): StripeRefund
    {
        if ($this->processRefundsWithoutOriginalTransaction) {
            return $refund->setStatus(StripeRefund::REFUND_PENDING);
        }

        // Find charge ID
        $transactionId = $refund->getTransactionId();
        if (!$transactionId) {
            try {
                $transactionId = $this->findTransactionId($refund);
                $refund->setTransactionId($transactionId);
            } catch (InvalidArgumentException $e) {
                return $this->putRefundOnHold(
                    $refund,
                    StripeRefund::REFUND_STATUS_REASON_NO_CHARGE_ID
                );
            }
        }

        // Check charge status
        try {
            $this->checkTransactionStatus($transactionId);
        } catch (InvalidRequestException $e) {
            return $this->abortRefund(
                $refund,
                $e->getMessage()
            );
        } catch (InvalidArgumentException $e) {
            switch ($e->getCode()) {
                // Problem is final, let's abort
                case 10:
                    return $this->abortRefund($refund, $e->getMessage());
                    // Problem is just temporary, let's put on hold
                case 20:
                    return $this->putRefundOnHold($refund, $e->getMessage());
            }
        }

        // All good
        return $refund->setStatus(StripeRefund::REFUND_PENDING);
    }

    private function findTransactionId(StripeRefund $refund): string
    {
        // Check for a transfer from the payment split workflow
        $transfer = current($this->stripeTransferRepository->findTransfersByOrderIds(
            [$refund->getMiraklOrderId()]
        ));

        if ($transfer && $transfer->getTransactionId()) {
            return $transfer->getTransactionId();
        }

        // Check for a payment mapping
        $commercialId = $refund->getMiraklCommercialOrderId();
        if ($commercialId) {
            $paymentMapping = current($this->paymentMappingRepository->findPaymentsByCommercialOrderIds(
                [$commercialId]
            ));

            if ($paymentMapping && $paymentMapping->getStripeChargeId()) {
                return $paymentMapping->getStripeChargeId();
            }
        }

        throw new InvalidArgumentException(StripeRefund::REFUND_STATUS_REASON_NO_CHARGE_ID);
    }

    private function checkTransactionStatus(string $trid): void
    {
        // Transaction number is a PaymentIntent
        if (0 === strpos($trid, 'pi_')) {
            $pi = $this->stripeClient->paymentIntentRetrieve($trid);
            switch ($pi->status) {
                case 'succeeded':
                    // Still have to check if it has been refunded
                    $ch = $pi->charges->data[0] ?? null;
                    assert($ch instanceof Charge);
                    $this->checkChargeStatus($ch);

                    return;
                case 'canceled':
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_CANCELED, $trid), 10);
                    /**
                     * if charge has a payment intent of status in the below condition, then cancel the payment intent -start
                     */
                case 'requires_payment_method':
                case 'requires_confirmation':
                case 'requires_action':
                case 'requires_capture':
                    $pi->cancel();
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $trid, $pi->status), 20);
                default:
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $trid, $pi->status), 20);
            }
        }

        // Transaction number is a Charge
        if (0 === strpos($trid, 'ch_') || 0 === strpos($trid, 'py_')) {
            $ch = $this->stripeClient->chargeRetrieve($trid);
            $this->checkChargeStatus($ch);

            return;
        }
    }

    private function checkChargeStatus(Charge $ch): void
    {
        /**
         * if charge has a payment intent of status in the below condition, then cancel the payment intent -start
         */
        $pi =  $ch->payment_intent;
        if (is_string($pi)) {
            $pi = $this->stripeClient->paymentIntentRetrieve($pi);
        }
        if ($pi) {
            switch ($pi->status) {
                case 'requires_payment_method':
                case 'requires_confirmation':
                case 'requires_action':
                case 'requires_capture':
                    $pi->cancel();
                    break;
            }
        }
        /**
         * if charge has a payment intent of status in the below condition, then cancel the payment intent -stop
         */

        switch ($ch->status) {
            case 'succeeded':
                if (false === $ch->captured) {
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $ch->id, $ch->status.' (not captured)'), 20);
                }

                if (true === $ch->refunded) {
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_CANCELED, $ch->id), 10);
                }

                return;
            case 'failed':
                throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_FAILED, $ch->id), 10);
            default:
                throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $ch->id, $ch->status), 20);
        }
    }

    private function putRefundOnHold(StripeRefund $refund, string $reason): StripeRefund
    {
        $this->logger->info(
            'Refund on hold: '.$reason,
            [
                'refund_id' => $refund->getMiraklRefundId(),
                'mirakle_order_id' => $refund->getMiraklOrderId(),
                'mirakle_commercial_order_id' => $refund->getMiraklRefundId(),
                'transaction_id' => $refund->getMiraklRefundId(),
                'stripe_refund_id' => $refund->getMiraklRefundId(),
                'type' => $refund->getType()
            ]
        );

        return $refund
            ->setStatus(StripeRefund::REFUND_ON_HOLD)
            ->setStatusReason(substr($reason, 0, 1024));
    }

    private function abortRefund(StripeRefund $refund, string $reason): StripeRefund
    {
        $this->logger->info(
            'Refund aborted: '.$reason,
            [
                'refund_id' => $refund->getMiraklRefundId(),
                'mirakle_order_id' => $refund->getMiraklOrderId(),
                'mirakle_commercial_order_id' => $refund->getMiraklRefundId(),
                'transaction_id' => $refund->getMiraklRefundId(),
                'stripe_refund_id' => $refund->getMiraklRefundId(),
                'type' => $refund->getType()

            ]
        );

        return $refund
            ->setStatus(StripeRefund::REFUND_ABORTED)
            ->setStatusReason(substr($reason, 0, 1024));
    }
}
