<?php

namespace App\Handler;

use App\Entity\StripeTransfer;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessTransferHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    public function __construct(
        StripeClient $stripeClient,
        StripeTransferRepository $stripeTransferRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTransferRepository = $stripeTransferRepository;
    }

    public function __invoke(ProcessTransferMessage $message): void
    {
        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $message->getStripeTransferId(),
        ]);
        if (
            null === $transfer
            || StripeTransfer::TRANSFER_PENDING !== $transfer->getStatus()
            || (
                null !== $message->getAttempt()
                && $message->getAttempt() !== $transfer->getStripeRequestAttempt()
            )
        ) {
            $this->logger->info('Ignoring stale transfer message.', [
                'stripe_transfer_id' => $message->getStripeTransferId(),
                'status' => $transfer?->getStatus(),
            ]);

            return;
        }

        if (null !== $transfer->getTransferId()) {
            $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
            $transfer->setStatusReason(null);
            $this->stripeTransferRepository->flush();

            return;
        }

        $type = $transfer->getType();
        $amount = $transfer->getAmount();
        $currency = $transfer->getCurrency();
        assert(null !== $amount && null !== $currency);

        try {
            $metadata = ['miraklId' => $transfer->getMiraklId()];
            switch ($type) {
                case StripeTransfer::TRANSFER_PRODUCT_ORDER:
                case StripeTransfer::TRANSFER_SERVICE_ORDER:
                case StripeTransfer::TRANSFER_EXTRA_CREDITS:
                    $accountMapping = $transfer->getAccountMapping();
                    assert(null !== $accountMapping);
                    assert(null !== $accountMapping->getStripeAccountId());

                    $metadata['miraklShopId'] = $accountMapping->getMiraklShopId();
                    $response = $this->stripeClient->createTransfer(
                        $currency,
                        $amount,
                        $accountMapping->getStripeAccountId(),
                        $transfer->getTransactionId(),
                        $metadata,
                        sprintf(
                            'mirakl-transfer-%s-attempt-%d',
                            $transfer->getId(),
                            $transfer->getStripeRequestAttempt()
                        )
                    );
                    break;
                case StripeTransfer::TRANSFER_SUBSCRIPTION:
                case StripeTransfer::TRANSFER_EXTRA_INVOICES:
                    $accountMapping = $transfer->getAccountMapping();
                    assert(null !== $accountMapping);
                    assert(null !== $accountMapping->getStripeAccountId());

                    $metadata['miraklShopId'] = $accountMapping->getMiraklShopId();
                    $response = $this->stripeClient->createTransferFromConnectedAccount(
                        $currency,
                        $amount,
                        $accountMapping->getStripeAccountId(),
                        $metadata,
                        sprintf(
                            'mirakl-transfer-%s-attempt-%d',
                            $transfer->getId(),
                            $transfer->getStripeRequestAttempt()
                        )
                    );
                    break;
                case StripeTransfer::TRANSFER_REFUND:
                    assert(null !== $transfer->getTransactionId());

                    $response = $this->stripeClient->reverseTransfer(
                        $amount,
                        $transfer->getTransactionId(),
                        $metadata,
                        sprintf(
                            'mirakl-transfer-%s-attempt-%d',
                            $transfer->getId(),
                            $transfer->getStripeRequestAttempt()
                        )
                    );
                    break;
            }

            if (isset($response->id)) {
                $transfer->setTransferId($response->id);
                $transfer->setStatus(StripeTransfer::TRANSFER_CREATED);
                $transfer->setStatusReason(null);
            }
        } catch (ApiErrorException $e) {
            $message = sprintf('Could not create Stripe Transfer: %s.', $e->getMessage());
            $this->logger->error($message, [
                'miraklId' => $transfer->getMiraklId(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
            $transfer->setStatusReason(substr($e->getMessage(), 0, 1024));
            if (!$this->stripeClient->isAmbiguousStripeFailure($e)) {
                $transfer->startNewStripeRequestAttempt();
            }
        }

        $this->stripeTransferRepository->flush();
    }
}
