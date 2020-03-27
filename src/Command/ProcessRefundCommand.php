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

class ProcessRefundCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-refund';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var bool
     */
    private $enablesAutoTransferCreation;

    /**
     * @var string
     */
    private $orderPaymentType;

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

    public function __construct(MessageBusInterface $bus, bool $enablesAutoTransferCreation, string $orderPaymentType, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripeTransferRepository $stripeTransferRepository, MiraklStripeMappingRepository $miraklStripeMappingRepository)
    {
        $this->bus = $bus;
        $this->enablesAutoTransferCreation = $enablesAutoTransferCreation;
        $this->orderPaymentType = $orderPaymentType;
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
            $this->processRefunds($miraklOrders);
        } else {
            $miraklOrders = $this->miraklClient->listPendingRefunds();
            $this->processRefunds($miraklOrders);
        }

        return 0;
    }

    private function processRefunds(array $miraklOrders): void
    {
        if (0 === count($miraklOrders)) {
            return;
        }

        $toCreateIds = array_column($miraklOrders, 'order_id');
        $alreadyCreatedMiraklIds = $this->stripeTransferRepository->findAlreadyCreatedMiraklIds($toCreateIds);
        foreach ($miraklOrders as $miraklOrder) {

            // Is the order managed by stripe
            if (!in_array($miraklOrder['order_id'], $alreadyCreatedMiraklIds)) {
                continue;
            }

            // Retrieve transfer to get the charge id
            $transfer = $this->stripeTransferRepository->findOneByMiraklOrder($miraklOrder['order_id']);
            $currency = $miraklOrder['currency_iso_code'];

            foreach ($miraklOrder['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $refund) {

                    $refundId = $refund['id'];
                    $refundAmount = $refund['amount'] * 100;

                    // Make the refund in stripe
                    $metadata = array('miraklRefundId' => $refundId);
                    $response = $this->stripeProxy->createRefund($refundAmount, $transfer->getTransactionId(), $metadata);
                    $stripeRefundId = $response->id;

                    // Valid refund on mirakl PA02
                    $refundPayload = array(
                        'amount' => $refund['amount'],
                        'currency_iso_code' => $currency,
                        'payment_status'=> 'OK',
                        'refund_id'=> $refundId,
                        'transaction_number' => $stripeRefundId
                    );
                    $this->miraklClient->validateRefunds(array($refundPayload));

                    // Make the reverse transfer in stripe
                    $this->stripeProxy->reverseTransfer($refundAmount, $transfer->getTransferId(), $metadata);
                }
            }
        }
    }
}
