<?php

namespace App\Handler;

use App\Entity\StripeTransfer;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessTransferHandler implements MessageHandlerInterface, LoggerAwareInterface
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

    public function __construct(
        MiraklClient $miraklClient,
        StripeProxy $stripeProxy,
        StripeTransferRepository $stripeTransferRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripeTransferRepository = $stripeTransferRepository;
    }

    public function __invoke(ProcessTransferMessage $message)
    {
        $transferToProcess = $this->stripeTransferRepository->findOneBy([
            'id' => $message->getStripeTransferId(),
        ]);
        $this->processTransfer($transferToProcess, $message->getType());
    }

    private function processTransfer(StripeTransfer $stripeTransfer, string $type): void
    {
        $currency = $stripeTransfer->getCurrency();
        $amount = $stripeTransfer->getAmount();

        $miraklStripeMapping = $stripeTransfer->getMiraklStripeMapping();
        $stripeTransferId = $stripeTransfer->getId();
        if (!$miraklStripeMapping) {
            $failedReason = sprintf('Stripe transfer %s has no associated Mirakl-Stripe mapping', $stripeTransferId);
            $this->logger->error($failedReason, [
                'Stripe transfer Id' => $stripeTransferId,
            ]);
            $stripeTransfer
                ->setFailedReason($failedReason)
                ->setStatus(StripeTransfer::TRANSFER_FAILED);
            $this->stripeTransferRepository->persistAndFlush($stripeTransfer);

            return;
        }

        $stripeAccountId = $miraklStripeMapping->getStripeAccountId();
        $miraklShopId = $miraklStripeMapping->getMiraklShopId();
        try {
            $metadata = [
                'miraklShopId' => $miraklShopId,
                'miraklId' => $stripeTransfer->getMiraklId(),
            ];
            $fromConnectedAccount = in_array($type, [
                StripeTransfer::TRANSFER_EXTRA_INVOICES,
                StripeTransfer::TRANSFER_SUBSCRIPTION,
            ]);
            $transactionId = $stripeTransfer->getTransactionId();
            $response = $this->stripeProxy->createTransfer($currency, $amount, $stripeAccountId, $transactionId, $metadata, $fromConnectedAccount);
            $transferId = $response->id;
            $stripeTransfer
                ->setStatus(StripeTransfer::TRANSFER_CREATED)
                ->setFailedReason(null)
                ->setTransferId($transferId);

        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not create Stripe Transfer: %s.', $e->getMessage()), [
                'miraklShopId' => $miraklShopId,
                'stripeTransferId' => $stripeTransfer->getMiraklId(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $stripeTransfer
                ->setStatus(StripeTransfer::TRANSFER_FAILED)
                ->setFailedReason(substr($e->getMessage(), 0, 1024));
        }
        $this->stripeTransferRepository->persistAndFlush($stripeTransfer);
    }
}
