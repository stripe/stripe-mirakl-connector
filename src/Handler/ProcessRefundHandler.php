<?php

namespace App\Handler;

use App\Entity\MiraklRefund;
use App\Entity\StripeTransfer;
use App\Message\ProcessRefundMessage;
use App\Repository\StripeTransferRepository;
use App\Repository\MiraklRefundRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
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
        $miraklRefund = $this->miraklRefundRepository->findOneBy([
            'miraklRefundId' => $message->geMiraklRefundId(),
        ]);

        if ($miraklRefund->getStatus() == MiraklRefund::REFUND_CREATED) {
            return;
        }

        $transfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => $miraklRefund->getMiraklOrderId(),
            'status' => StripeTransfer::TRANSFER_CREATED,
            'type' => StripeTransfer::TRANSFER_ORDER
        ]);

        if(is_null($transfer)) {
            return;
        }

        try {
            // Refund and transfer back in Stripe
            $this->processRefundInStripe($miraklRefund, $transfer);
            $this->processReversalInStripe($miraklRefund, $transfer);
            // Valid refund on mirakl PA02
            $this->validateRefundOnMirakl($miraklRefund, $transfer);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not create Stripe Payout: %s.', $e->getMessage()), [
                'miraklRefundId' => $refund->getMiraklRefundId(),
                'miraklOrderId' => $refund->getMiraklOrderId()
            ]);
            $miraklRefund
                ->setStatus(MiraklRefund::REFUND_FAILED)
                ->setFailedReason(substr($e->getMessage(), 0, 1024));
        }
        $this->miraklRefundRepository->persistAndFlush($miraklRefund);
    }

    private function processRefundInStripe(MiraklRefund $refund, StripeTransfer $transfer) {
        if (!is_null($refund->getStripeRefundId())) {
            return;
        }

        // Make sure refund is not already present in Stripe
        if ($this->isRefundAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId())) {
            return;
        }

        // Make the refund in stripe
        $metadata = array('miraklRefundId' => $refund->getMiraklRefundId());
        $response = $this->stripeProxy->createRefund($refund->getAmount(), $transfer->getTransactionId(), $metadata);
        $refund->setStripeRefundId($response->id);
    }

    private function processReversalInStripe(MiraklRefund $refund, StripeTransfer $transfer) {
        if (!is_null($refund->getStripeReversalId())) {
            return;
        }

        // Make sure reversal is not already present in Stripe
        if ($this->isReversalAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId())) {
            return;
        }

        // Make the reverse transfer in stripe
        $metadata = array('miraklRefundId' => $refund->getMiraklRefundId());
        $response = $this->stripeProxy->reverseTransfer($refund->getAmount(), $transfer->getTransferId(), $metadata);
        $refund->setStripeReversalId($response->id);
        
    }

    private function isRefundAlreadyPresentInStripe(StripeTransfer $transfer, string $miraklRefundId) {
        $refunds = $this->stripeProxy->listRefunds($transfer->getTransactionId());
        $miraklRefundIds = array_map(function($item) {
            return $item->metadata['miraklRefundId'];
        }, iterator_to_array ($refunds->getIterator()));

        return in_array($miraklRefundId, $miraklRefundIds);
    }

    private function isReversalAlreadyPresentInStripe(StripeTransfer $transfer, string $miraklRefundId) {
        $reversals = $this->stripeProxy->listReversals($transfer->getTransferId());
        $miraklRefundIds = array_map(function($item) {
            return $item->metadata['miraklRefundId'];
        }, iterator_to_array ($reversals->getIterator()));

        return in_array($miraklRefundId, $miraklRefundIds);
    }

    private function validateRefundOnMirakl(MiraklRefund $refund, StripeTransfer $transfer) {
        if($this->isRefundAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId()) && 
        $this->isReversalAlreadyPresentInStripe($transfer, $refund->getMiraklRefundId())) {
            $refundPayload = array(
                'amount' => $refund->getAmount() / 100,
                'currency_iso_code' => $refund->getCurrency(),
                'payment_status'=> 'OK',
                'refund_id'=> $refund->getMiraklRefundId()
            );
            $refundPayload['transaction_number'] = $refund->getStripeRefundId();
            $this->miraklClient->validateRefunds(array($refundPayload));
            $refund->setStatus(MiraklRefund::REFUND_CREATED);
        }
    }
}
