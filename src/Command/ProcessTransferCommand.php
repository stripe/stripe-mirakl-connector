<?php

namespace App\Command;

use App\Entity\StripeTransfer;
use App\Message\ProcessTransferMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessTransferCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-transfer';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var bool
     */
    private $enablesAutoTransferCreation;

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
     * @var MiraklStripeMappingRepository
     */
    private $miraklStripeMappingRepository;

    public function __construct(MessageBusInterface $bus, bool $enablesAutoTransferCreation, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripeTransferRepository $stripeTransferRepository, MiraklStripeMappingRepository $miraklStripeMappingRepository)
    {
        $this->bus = $bus;
        $this->enablesAutoTransferCreation = $enablesAutoTransferCreation;
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_order_ids', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $miraklOrderIds = $input->getArgument('mirakl_order_ids');

        if (!$this->enablesAutoTransferCreation) {
            $output->writeln('Transfer creation is disabled.');
            $output->writeln('You can enable it by setting the environement variable ENABLES_AUTOMATIC_TRANSFER_CREATION to true.');

            return 0;
        }
        // getArgument should never return a string when using InputArgument::IS_ARRAY
        assert(null === $miraklOrderIds || is_array($miraklOrderIds));
        if (null == $miraklOrderIds) {
            $output->writeln('No Mirakl order ids specified. Will fetch latest orders from Mirakl.');
            $output->writeln(sprintf('If needed, you can run `bin/console %s Order_0018-A` for instance.', self::$defaultName));
        }

        if (null !== $miraklOrderIds && count($miraklOrderIds) > 0) {
            $this->logger->info('Creating specified Mirakl orders', ['order_ids' => $miraklOrderIds]);
            $miraklOrders = $this->miraklClient->listMiraklOrders(null, $miraklOrderIds);
            $this->createTransfers($miraklOrders);
        } else {
            $lastMiraklUpdateTime = $this->stripeTransferRepository->getLastMiraklUpdateTime();
            $miraklOrders = $this->miraklClient->listMiraklOrders($lastMiraklUpdateTime, null);
            $this->createTransfers($miraklOrders);
        }

        return 0;
    }

    private function createTransfers(array $miraklOrders): void
    {
        if (0 === count($miraklOrders)) {
            return;
        }

        $ignoredStripeTransferStatuses = array(StripeTransfer::TRANSFER_CREATED);
        $ignoredOrderLineStates = array('REFUSED', 'CANCELED');

        $existingStripeTransfers = $this->stripeTransferRepository->findBy([
            'miraklId' => array_column($miraklOrders, 'order_id'),
            'type' => StripeTransfer::TRANSFER_ORDER,
        ]);
        $existingStripeTransfersByMiraklId = [];
        foreach($existingStripeTransfers as $transfer) {
            $existingStripeTransfersByMiraklId[$transfer->getMiraklId()] = $transfer;
        }

        foreach ($miraklOrders as $miraklOrder) {
            $hasStripeTransfer = array_key_exists($miraklOrder['order_id'], $existingStripeTransfersByMiraklId);

            if ($hasStripeTransfer) {
                $orderStripeTransfer = $existingStripeTransfersByMiraklId[$miraklOrder['order_id']];
                $orderStripeTransfer
                    ->setStatus(StripeTransfer::TRANSFER_PENDING);
            } else {
                $orderStripeTransfer = new StripeTransfer();
                $orderStripeTransfer
                    ->setType(StripeTransfer::TRANSFER_ORDER)
                    ->setStatus(StripeTransfer::TRANSFER_PENDING);
            }

            if (in_array($orderStripeTransfer->getStatus(), $ignoredStripeTransferStatuses)) {
                continue;
            }

            $transactionId = $miraklOrder['transaction_number'];
            if (0 === strpos($transactionId, 'ch_') || 0 === strpos($transactionId, 'py_')) {
                $orderStripeTransfer->setTransactionId($transactionId);
            }

            $taxes = 0;
            if (isset($miraklOrder['order_lines']) && !empty($miraklOrder['order_lines'])) {
                foreach ($miraklOrder['order_lines'] as $orderLine) {
                    if (in_array($orderLine['order_line_state'], $ignoredOrderLineStates)) {
                        continue;
                    }

                    foreach ((array) $orderLine['shipping_taxes'] as $tax) {
                        $taxes += (float) $tax['amount'];
                    }

                    foreach ((array) $orderLine['taxes'] as $tax) {
                        $taxes += (float) $tax['amount'];
                    }
                }
            }

            $amountToTransfer = (int) (100 * ($miraklOrder['total_price'] + $taxes - $miraklOrder['total_commission']));
            if ($amountToTransfer < 0) {
                $failedReason = sprintf('Amount to tranfer must be positive');
                $this->logger->error($failedReason, [
                    'amount' => $amountToTransfer,
                ]);
                $orderStripeTransfer
                    ->setStatus(StripeTransfer::TRANSFER_INVALID_AMOUNT)
                    ->setFailedReason($failedReason);
            }

            $miraklShopId = $miraklOrder['shop_id'];
            $miraklStripeMapping = $this->miraklStripeMappingRepository->findOneBy([
                'miraklShopId' => $miraklShopId,
            ]);

            if (null === $miraklStripeMapping) {
                $failedReason = sprintf('Stripe-Mirakl Mapping associated with Mirakl Shop ID %s does not exist', $miraklShopId);
                $this->logger->error($failedReason, [
                    'miraklShopId' => $miraklShopId,
                ]);
                $orderStripeTransfer
                    ->setStatus(StripeTransfer::TRANSFER_FAILED)
                    ->setFailedReason($failedReason);
            } else {
                $orderStripeTransfer
                    ->setMiraklStripeMapping($miraklStripeMapping);
            }

            $miraklUpdateTime = \DateTime::createFromFormat(MiraklClient::DATE_FORMAT, $miraklOrder['last_updated_date']);
            if (!$miraklUpdateTime) {
                $failedReason = sprintf('Cannot parse last_updated_date from Mirakl: %s', $miraklOrder['last_updated_date']);
                $this->logger->error($failedReason, ['last_updated_date' => $miraklOrder['last_updated_date']]);
                $orderStripeTransfer
                    ->setStatus(StripeTransfer::TRANSFER_FAILED)
                    ->setFailedReason($failedReason);
                $miraklUpdateTime = new \DateTime();
                $miraklUpdateTime->setTimestamp(0);
            }

            $orderStripeTransfer
                ->setAmount($amountToTransfer)
                ->setMiraklId($miraklOrder['order_id'])
                ->setCurrency($miraklOrder['currency_iso_code'])
                ->setMiraklUpdateTime($miraklUpdateTime);

            if ($hasStripeTransfer) {
                $this->stripeTransferRepository->flush();
            } else {
                $this->stripeTransferRepository->persistAndFlush($orderStripeTransfer);
            }

            if (StripeTransfer::TRANSFER_PENDING === $orderStripeTransfer->getStatus() && null !== $orderStripeTransfer->getId()) {
                $message = new ProcessTransferMessage(StripeTransfer::TRANSFER_ORDER, $orderStripeTransfer->getId());
                $this->bus->dispatch($message);
            }
        }
    }
}
