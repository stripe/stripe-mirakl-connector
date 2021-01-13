<?php

namespace App\Command;

use App\Exception\InvalidArgumentException;
use App\Message\ProcessTransferMessage;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\PaymentSplitService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentSplitCommand extends Command implements LoggerAwareInterface
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
     * @var ConfigService
     */
    private $configService;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentSplitService
     */
    private $paymentSplitService;

    public function __construct(
        MessageBusInterface $bus,
        bool $enablesAutoTransferCreation,
        ConfigService $configService,
        MiraklClient $miraklClient,
        PaymentSplitService $paymentSplitService
    ) {
        $this->bus = $bus;
        $this->enablesAutoTransferCreation = $enablesAutoTransferCreation;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->paymentSplitService = $paymentSplitService;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_order_ids', InputArgument::IS_ARRAY);
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        if (!$this->enablesAutoTransferCreation) {
            $output->writeln('Transfer creation is disabled.');
            $output->writeln('You can enable it by setting the environement variable ENABLES_AUTOMATIC_TRANSFER_CREATION to true.');

            return 0;
        }

        // Order IDs passed as argument
        $orderIds = (array) $input->getArgument('mirakl_order_ids');
        if (!empty($orderIds)) {
            $this->processProvidedOrderIds($orderIds);
            return 0;
        }

        // Start with transfers that couldn't be completed before
        $this->processBacklog();

        // Now new orders
        $this->processNewOrders();

        return 0;
    }

    private function processProvidedOrderIds($orderIds)
    {
        $this->logger->info('Executing provided orders', [ 'order_ids' => $orderIds ]);
        $transfers = $this->paymentSplitService->getTransfersFromOrders(
            $this->miraklClient->listOrdersById($orderIds)
        );
        $this->dispatchTransfers($transfers);
    }

    private function processBacklog()
    {
        $this->logger->info('Executing backlog');
        $backlog = $this->paymentSplitService->getRetriableTransfers();
        if (empty($backlog)) {
            $this->logger->info('No backlog');
            return;
        }

        $chunks = array_chunk($backlog, 10, true);
        foreach ($chunks as $chunk) {
            $this->dispatchTransfers(
                $this->paymentSplitService->updateTransfersFromOrders(
                                    $chunk,
                                    $this->miraklClient->listOrdersById(array_keys($chunk))
                                )
            );
        }
    }

    private function processNewOrders()
    {
        $checkpoint = $this->configService->getPaymentSplitCheckpoint() ?? '';
        $this->logger->info('Executing for recent orders, checkpoint: ' . $checkpoint);
        $orders = $this->miraklClient->listOrdersByDate($checkpoint);
        if (empty($orders)) {
            $this->logger->info('No new order');
            return;
        }

        $this->dispatchTransfers(
            $this->paymentSplitService->getTransfersFromOrders($orders)
        );

        $checkpoint = $this->updateCheckpoint($orders, $checkpoint);
        $this->configService->setPaymentSplitCheckpoint($checkpoint);
        $this->logger->info('Setting new checkpoint: ' . $checkpoint);
    }

    // Return the last valid created_date or the current checkpoint
    private function updateCheckpoint(array $orders, ?string $checkpoint): ?string
    {
        $orders = array_reverse($orders);
        foreach ($orders as $order) {
            try {
                MiraklClient::getDatetimeFromString($order['created_date']);
                return $order['created_date'];
            } catch (InvalidArgumentException $e) {
                // Shouldn't happen, see MiraklClient::getDatetimeFromString
            }
        }

        return $checkpoint;
    }

    private function dispatchTransfers(array $transfers): void
    {
        foreach ($transfers as $transfer) {
            if ($transfer->isDispatchable()) {
                $this->bus->dispatch(new ProcessTransferMessage(
                    $transfer->getId()
                ));
            }
        }
    }
}
