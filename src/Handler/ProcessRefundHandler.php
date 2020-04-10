<?php

namespace App\Handler;

use App\Entity\MiraklRefund;
use App\Entity\StripeTransfer;
use App\Exception\RefundProcessException;
use App\Message\ProcessRefundMessage;
use App\Repository\StripeTransferRepository;
use App\Repository\MiraklRefundRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessRefundHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var MiraklRefundRepository
     */
    private $miraklRefundRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripeProxy $stripeProxy,
        StripeTransferRepository $stripeTransferRepository,
        MiraklRefundRepository $miraklRefundRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklRefundRepository = $miraklRefundRepository;
    }

    public function __invoke(ProcessRefundMessage $message)
    {
        $refund = $this->miraklRefundRepository->findOneBy([
            'miraklRefundId' => $message->geMiraklRefundId(),
        ]);

        if (MiraklRefund::REFUND_CREATED == $refund->getStatus()) {
            // Already processed nothing todo
            return;
        }

        $transfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => $refund->getMiraklOrderId(),
            'status' => StripeTransfer::TRANSFER_CREATED,
            'type' => StripeTransfer::TRANSFER_ORDER,
        ]);

        if (is_null($transfer)) {
            $failedReason = sprintf('Mirakl refund id: %s and orderId: %s has no stripe transfer in connector', $refund->getMiraklRefundId(), $refund->getMiraklOrderId());
            $refund
                ->setStatus(MiraklRefund::REFUND_FAILED)
                ->setFailedReason($failedReason);
            $this->miraklRefundRepository->persistAndFlush($refund);

            return;
        }

        try {
            // Create refund in Stripe and validate it in Mirakl
            $this->processRefundInStripe($refund, $transfer);
            $this->validateRefundInMirakl($refund);

            // Create the reversal
            $this->processReversalInStripe($refund, $transfer);

            // Check that the 3 steps are successful
            $this->moveRefundInStatusCreated($refund);
        } catch (RefundProcessException $e) {
            $this->logger->error(sprintf('Could not process refund: %s.', $e->getMessage()), [
                'miraklRefundId' => $refund->getMiraklRefundId(),
                'miraklOrderId' => $refund->getMiraklOrderId(),
            ]);
            $refund
                ->setStatus(MiraklRefund::REFUND_FAILED)
                ->setFailedReason(substr($e->getMessage(), 0, 1024));
        }

        $this->miraklRefundRepository->persistAndFlush($refund);
    }

    private function processRefundInStripe(MiraklRefund $refund, StripeTransfer $transfer)
    {
        if (!is_null($refund->getStripeRefundId())) {
            // We got stripe refund id
            // Charge has already been refunded
            return;
        }

        if ($this->isRefundAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId())) {
            // Charge has a refund with the mirakl refund id
            // Our systems are not in-sync but charge has already been refunded
            return;
        }

        // Make the refund in stripe
        try {
            $metadata = ['miraklRefundId' => $refund->getMiraklRefundId()];
            $response = $this->stripeProxy->createRefund($refund->getAmount(), $transfer->getTransactionId(), $metadata);
            $refund->setStripeRefundId($response->id);
        } catch (ApiErrorException $e) {
            throw new \App\Exception\RefundProcessException(sprintf('Could not create refund in stripe: %s', $e->getMessage()));
        }
    }

    private function processReversalInStripe(MiraklRefund $refund, StripeTransfer $transfer)
    {
        if (!is_null($refund->getStripeReversalId())) {
            // We got stripe reversal id
            // Transfer has already been reversed
            return;
        }

        if ($this->isReversalAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId())) {
            // Transfer has a reversal with the mirakl refund id
            // Our systems are not in-sync but transfer has already been reversed
            return;
        }

        // Make the reverse transfer in stripe
        try {
            $metadata = ['miraklRefundId' => $refund->getMiraklRefundId()];
            $response = $this->stripeProxy->reverseTransfer($refund->getAmount(), $transfer->getTransferId(), $metadata);
            $refund->setStripeReversalId($response->id);
        } catch (ApiErrorException $e) {
            throw new \App\Exception\RefundProcessException(sprintf('Could not create reversal in stripe: %s', $e->getMessage()));
        }
    }

    private function isRefundAlreadyPresentInStripe(StripeTransfer $transfer, ?string $miraklRefundId)
    {
        $refunds = $this->stripeProxy->listRefunds($transfer->getTransactionId());
        $miraklRefundIds = array_map(
            function ($item) {
                return $item->metadata['miraklRefundId'];
            },
            iterator_to_array($refunds->getIterator())
        );

        return in_array($miraklRefundId, $miraklRefundIds);
    }

    private function isReversalAlreadyPresentInStripe(StripeTransfer $transfer, ?string $miraklRefundId)
    {
        $reversals = $this->stripeProxy->listReversals($transfer->getTransferId());
        $miraklRefundIds = array_map(
            function ($item) {
                return $item->metadata['miraklRefundId'];
            },
            iterator_to_array($reversals->getIterator())
        );

        return in_array($miraklRefundId, $miraklRefundIds);
    }

    private function validateRefundInMirakl(MiraklRefund $refund)
    {
        if (!is_null($refund->getMiraklValidationTime())) {
            // Refund has been confirmed in our system
            return;
        }

        if (is_null($refund->getStripeRefundId())) {
            throw new \App\Exception\RefundProcessException(sprintf('Mirakl refund id: %s and orderId: %s has no stripe refund id in connector', $refund->getMiraklRefundId(), $refund->getMiraklOrderId()));
        }

        // Confirm the refund in Mirakl
        try {
            $refundPayload = [
                'amount' => $refund->getAmount() / 100,
                'currency_iso_code' => $refund->getCurrency(),
                'payment_status' => 'OK',
                'refund_id' => $refund->getMiraklRefundId(),
                'transaction_number' => $refund->getStripeRefundId(),
            ];
            $this->miraklClient->validateRefunds([$refundPayload]);
        } catch (ClientException $e) {
            $errorMessage = $e->getResponse()->getContent(false);
            $statusCode = $e->getResponse()->getStatusCode();

            if (400 == $statusCode && false !== strpos($errorMessage, 'cannot be processed because it is in state REFUNDED')) {
                // Refund already confirmed in Mirakl
                return;
            }

            throw new \App\Exception\RefundProcessException(sprintf('Mirakl refund id: %s and orderId: %s failed to be validated in mirakl. code: %s message: %s', $refund->getMiraklRefundId(), $refund->getMiraklOrderId(), $statusCode, $errorMessage));
        }

        $now = new \DateTime();
        $refund->setMiraklValidationTime($now);
    }

    private function moveRefundInStatusCreated(MiraklRefund $refund)
    {
        // We need the 3 api calls to be successful to declare the refund CREATED
        if (is_null($refund->getMiraklValidationTime())) {
            throw new \App\Exception\RefundProcessException(sprintf('Mirakl refund id: %s and orderId: %s has no mirakl validation time', $refund->getMiraklRefundId(), $refund->getMiraklOrderId()));
        }

        if (is_null($refund->getStripeRefundId())) {
            throw new \App\Exception\RefundProcessException(sprintf('Mirakl refund id: %s and orderId: %s has no stripe refund id in connector', $refund->getMiraklRefundId(), $refund->getMiraklOrderId()));
        }

        if (is_null($refund->getStripeReversalId())) {
            throw new \App\Exception\RefundProcessException(sprintf('Mirakl refund id: %s and orderId: %s has no stripe reversal id in connector', $refund->getMiraklRefundId(), $refund->getMiraklOrderId()));
        }

        $refund->setStatus(MiraklRefund::REFUND_CREATED);
    }
}
