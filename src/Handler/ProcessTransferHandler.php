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
