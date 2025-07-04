<?php

namespace App\Handler;

use App\Entity\MiraklProductOrder;
use App\Entity\StripeTransfer;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeTransferRepository;
use App\Service\StripeClient;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessTransferHandler implements MessageHandlerInterface, LoggerAwareInterface
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

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    public function __construct(
        StripeClient $stripeClient,
        StripeTransferRepository $stripeTransferRepository,
        MiraklClient $miraklClient
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklClient = $miraklClient;
    }

    public function __invoke(ProcessTransferMessage $message): void
    {
        $transfer = $this->stripeTransferRepository->findOneBy([
            'id' => $message->getStripeTransferId(),
        ]);
        assert(null !== $transfer);
        assert(StripeTransfer::TRANSFER_CREATED !== $transfer->getStatus());

        $type = $transfer->getType();
        $amount = $transfer->getAmount();
        $currency = $transfer->getCurrency();
        assert(null !== $amount && null !== $currency);

        try {
            $metadata = ['miraklId' => $transfer->getMiraklId()];
            if ($type == StripeTransfer::TRANSFER_PRODUCT_ORDER) {
                $order = $this->miraklClient->listProductOrdersById([$transfer->getMiraklId()]);
                $metadata = array_merge($metadata, $this->additionalMetaDataForProductOrderTransfer($order[$transfer->getMiraklId()]));
            }
            switch ($type) {
                case StripeTransfer::TRANSFER_PRODUCT_ORDER:
                case StripeTransfer::TRANSFER_SERVICE_ORDER:
                case StripeTransfer::TRANSFER_REFUND:
                case StripeTransfer::TRANSFER_EXTRA_INVOICES:
                case StripeTransfer::TRANSFER_SUBSCRIPTION:
                case StripeTransfer::TRANSFER_EXTRA_CREDITS:
                    break;
                case StripeTransfer::TRANSFER_INVOICE:
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
                'transferId' => $transfer->getTransferId(),
                'transactionId' => $transfer->getTransactionId(),
                'amount' => $transfer->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
                'miraklShopId' => $transfer->getAccountMapping()->getMiraklShopId() ?? 'No shop id available.',
                'accountMapping' => json_encode($transfer->getAccountMapping() ?? []),
                'file' => $e->getFile() ??  'No file available.',
                'line' => $e->getLine() ?? 'No line available.',
                'trace' => $e->getTraceAsString() ?? 'No trace available.',
            ]);

            $transfer->setStatus(StripeTransfer::TRANSFER_FAILED);
            $transfer->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->stripeTransferRepository->flush();
    }

    private function additionalMetaDataForProductOrderTransfer(MiraklProductOrder $order): array
    {
        return [
            'ORDER_TAX_AMOUNT' => $order->getTotalTypeTaxes('taxes'),
            'SHIPPING_TAX_AMOUNT' => $order->getTotalTypeTaxes('shipping_taxes'),
            'COMMISSION_FEES' => $order->getOperatorCommission()
        ];
    }
}
