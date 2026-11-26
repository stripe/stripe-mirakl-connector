<?php

namespace App\Factory;

use App\Entity\MiraklPendingRefund;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\StripeRefund;
use App\Exception\InvalidArgumentException;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use App\Service\StripeAmount;
use App\Service\MiraklClient;
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

    private MiraklClient $miraklClient;

    public function __construct(
        PaymentMappingRepository $paymentMappingRepository,
        StripeTransferRepository $stripeTransferRepository,
        StripeClient $stripeClient,
        MiraklClient $miraklClient
    ) {
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripeClient = $stripeClient;
        $this->miraklClient = $miraklClient;
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
        $refund->setCurrency(strtolower($pendingRefund->getCurrency()));
        $refund->setMiraklRefundId($pendingRefund->getId());
        return $this->updateRefund($refund, $pendingRefund);
    }

    public function updateRefund(StripeRefund $refund, ?MiraklPendingRefund $pendingRefund = null): StripeRefund
    {
        if ($pendingRefund && !$refund->getStripeRefundId()) {
            try {
                $this->updateRefundAmounts($refund, $pendingRefund);
            } catch (InvalidArgumentException $e) {
                $refund->setAmount(0);

                if (20 === $e->getCode()) {
                    return $this->putRefundOnHold($refund, $e->getMessage());
                }

                return $this->abortRefund($refund, $e->getMessage());
            }
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
            $this->checkTransactionStatus($transactionId, $refund->getCurrency());
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

    private function updateRefundAmounts(StripeRefund $refund, MiraklPendingRefund $pendingRefund): void
    {
        $miraklAmount = StripeAmount::toMinorUnits($pendingRefund->getAmount(), $pendingRefund->getCurrency());
        if ($miraklAmount <= 0) {
            throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_INVALID_AMOUNT, $miraklAmount));
        }

        $amount = $miraklAmount;
        if ($pendingRefund instanceof MiraklServicePendingRefund) {
            $order = current($this->miraklClient->listServiceOrdersById([$pendingRefund->getOrderId()]));
        } else {
            $order = current($this->miraklClient->listProductOrdersById([$pendingRefund->getOrderId()]));
        }
        if (!$order) {
            throw new InvalidArgumentException('Cannot find order details to calculate the tax-inclusive refund.', 20);
        }

        // Mirakl's TAX_EXCLUDED amounts are net of tax; TAX_INCLUDED amounts
        // already contain tax, so adding the tax fields again would double-refund.
        if (!$order->isTaxIncluded()) {
            $refund->setMiraklRefundId($pendingRefund->getId());
            $refund->setMiraklOrderLineId($pendingRefund->getOrderLineId());
            if (!$order->hasRefund($refund)) {
                throw new InvalidArgumentException(
                    'Cannot find refund details in the Mirakl order to calculate refund taxes.',
                    20
                );
            }
            $tax = StripeAmount::toMinorUnits($order->getRefundedTax($refund), $pendingRefund->getCurrency());
            $amount += $tax;
        }
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_INVALID_AMOUNT, $amount));
        }

        $refund->setAmount($amount);
        $refund->setMiraklAmount($miraklAmount);
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

    private function checkTransactionStatus(string $trid, string $currency): void
    {
        // Transaction number is a PaymentIntent
        if (0 === strpos($trid, 'pi_')) {
            $pi = $this->stripeClient->paymentIntentRetrieve($trid);
            if (strtolower((string) ($pi->currency ?? '')) !== strtolower($currency)) {
                throw new InvalidArgumentException('Refund currency differs from Stripe PaymentIntent currency.', 10);
            }
            switch ($pi->status) {
                case 'succeeded':
                    // Still have to check if it has been refunded
                    $ch = $pi->latest_charge ?? null;
                    if (is_string($ch)) {
                        $ch = $this->stripeClient->chargeRetrieve($ch);
                    }
                    if (!$ch instanceof Charge) {
                        throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $trid, 'no latest charge'), 20);
                    }
                    $this->checkChargeStatus($ch, $currency);

                    return;
                case 'canceled':
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_CANCELED, $trid), 10);
                default:
                    throw new InvalidArgumentException(sprintf(StripeRefund::REFUND_STATUS_REASON_PAYMENT_NOT_READY, $trid, $pi->status), 20);
            }
        }

        // Transaction number is a Charge
        if (0 === strpos($trid, 'ch_') || 0 === strpos($trid, 'py_')) {
            $ch = $this->stripeClient->chargeRetrieve($trid);
            $this->checkChargeStatus($ch, $currency);

            return;
        }
    }

    private function checkChargeStatus(Charge $ch, string $currency): void
    {
        if (strtolower((string) ($ch->currency ?? '')) !== strtolower($currency)) {
            throw new InvalidArgumentException('Refund currency differs from Stripe Charge currency.', 10);
        }

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
            ['refund_id' => $refund->getMiraklRefundId()]
        );

        return $refund
            ->setStatus(StripeRefund::REFUND_ON_HOLD)
            ->setStatusReason(substr($reason, 0, 1024));
    }

    private function abortRefund(StripeRefund $refund, string $reason): StripeRefund
    {
        $this->logger->info(
            'Refund aborted: '.$reason,
            ['refund_id' => $refund->getMiraklRefundId()]
        );

        return $refund
            ->setStatus(StripeRefund::REFUND_ABORTED)
            ->setStatusReason(substr($reason, 0, 1024));
    }
}
