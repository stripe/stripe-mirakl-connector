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
            $this->validateRefundOnMirakl($miraklRefund);
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
        if (is_null($refund->getStripeRefundId())) {
            // Make sure refund is not already present in Stripe
            $refunds = $this->stripeProxy->listRefunds($transfer->getTransactionId());
            $miraklRefundIds = array_map(function($item) {
                return $item->metadata['miraklRefundId'];
            }, iterator_to_array ($refunds->getIterator()));

            if (in_array($refund->getMiraklRefundId(), $miraklRefundIds)) {
                return;
            }

            // Make the refund in stripe
            $metadata = array('miraklRefundId' => $refund->getMiraklRefundId());
            $response = $this->stripeProxy->createRefund($refund->getAmount(), $transfer->getTransactionId(), $metadata);
            $stripeRefundId = $response->id;

            $refund->setStripeRefundId($stripeRefundId);
            $this->miraklRefundRepository->persist($refund);
        }
    }

    private function processReversalInStripe(MiraklRefund $refund, StripeTransfer $transfer) {
        // Make the reverse transfer in stripe
        if (is_null($refund->getStripeReversalId())) {
            // Make sure refund is not already present in Stripe
            $reversals = $this->stripeProxy->listReversals($transfer->getTransferId());
            $miraklRefundIds = array_map(function($item) {
                return $item->metadata['miraklRefundId'];
            }, iterator_to_array ($reversals->getIterator()));

            if (in_array($refund->getMiraklRefundId(), $miraklRefundIds)) {
                return;
            }

            $metadata = array('miraklRefundId' => $refund->getMiraklRefundId());
            $response = $this->stripeProxy->reverseTransfer($refund->getAmount(), $transfer->getTransferId(), $metadata);
            $refund->setStripeReversalId($response->id);
            $this->miraklRefundRepository->persist($refund);
        }
    }

    private function validateRefundOnMirakl(MiraklRefund $refund) {
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
