<?php

namespace App\Handler;

use App\Entity\StripeTransfer;
use App\Factory\StripeTransferFactory;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

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

    private StripeTransferFactory $stripeTransferFactory;

    private MessageBusInterface $bus;

    public function __construct(
        StripeClient $stripeClient,
        StripeTransferRepository $stripeTransferRepository,
        StripeTransferFactory $stripeTransferFactory,
        MessageBusInterface $bus
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripeTransferFactory = $stripeTransferFactory;
        $this->bus = $bus;
    }

    public function __invoke(ProcessTransferMessage $message): void
    {
        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $message->getStripeTransferId(),
        ]);
        assert(null !== $transfer);
        if (StripeTransfer::TRANSFER_CREATED === $transfer->getStatus()) {
            return;
        }

        $type = $transfer->getType();
        $amount = $transfer->getAmount();
        $currency = $transfer->getCurrency();
        assert(null !== $amount && null !== $currency);
        $shortfallReversal = null;

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
                        $metadata
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
                        $metadata
                    );
                    break;
                case StripeTransfer::TRANSFER_REFUND:
                    assert(null !== $transfer->getTransactionId());

                    $response = $this->stripeClient->reverseTransfer(
                        $amount,
                        $transfer->getTransactionId(),
                        $metadata,
                        sprintf('mirakl-transfer-reversal-%d', $transfer->getId())
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
        }

        $this->stripeTransferRepository->flush();

        if (StripeTransfer::TRANSFER_CREATED === $transfer->getStatus()) {
            try {
                $shortfallReversal = $this->stripeTransferFactory
                    ->createTaxShortfallReversalForCompletedTransfer($transfer);
                if ($shortfallReversal) {
                    $this->stripeTransferRepository->flush();
                }
            } catch (\Throwable $e) {
                $this->logger->error('Could not prepare tax shortfall reversal.', [
                    'miraklId' => $transfer->getMiraklId(),
                    'exception' => $e,
                ]);
            }
        }

        if ($shortfallReversal && $shortfallReversal->isDispatchable()) {
            $this->bus->dispatch(new ProcessTransferMessage($shortfallReversal->getId()));
        }
    }
}
