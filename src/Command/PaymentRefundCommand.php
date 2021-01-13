<?php

namespace App\Command;

use App\Entity\StripeRefund;
use App\Message\ProcessRefundMessage;
use App\Message\ProcessTransferMessage;
use App\Repository\StripeRefundRepository;
use App\Service\MiraklClient;
use App\Service\PaymentRefundService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentRefundCommand extends Command implements LoggerAwareInterface
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
    private $enablesAutoRefundCreation;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentRefundService
     */
    private $paymentRefundService;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    public function __construct(
        MessageBusInterface $bus,
        MiraklClient $miraklClient,
        PaymentRefundService $paymentRefundService,
        bool $enablesAutoRefundCreation
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->paymentRefundService = $paymentRefundService;
        $this->enablesAutoRefundCreation = $enablesAutoRefundCreation;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        if (!$this->enablesAutoRefundCreation) {
            $output->writeln('Refund creation is disabled.');
            $output->writeln('You can enable it by setting the environment variable ENABLES_AUTOMATIC_REFUND_CREATION to true.');

            return 0;
        }

        // Start with transfers that couldn't be completed before
        $this->processBacklog();

        // Now pending refunds
        $this->processPendingRefunds();

        return 0;
    }

    private function processBacklog()
    {
        $this->logger->info('Executing backlog');
        $backlog = $this->paymentRefundService->getRetriableTransfers();
        if (empty($backlog)) {
            $this->logger->info('No backlog');
            return;
        }

        $this->dispatchTransfers(
            $this->paymentRefundService->updateTransfers($backlog)
        );
    }

    private function processPendingRefunds(): void
    {
        $orderRefunds = $this->miraklClient->listPendingRefunds();
        if (empty($orderRefunds)) {
            $this->logger->info('No pending refund');
            return;
        }

        $refunds = $this->paymentRefundService
                                    ->getRefundsFromOrderRefunds($orderRefunds);
        $transfers = $this->paymentRefundService
                                    ->getTransfersFromOrderRefunds($orderRefunds);

        $this->dispatchRefunds($refunds);
        $this->dispatchTransfers($transfers);
    }

    private function dispatchRefunds(array $refunds): void
    {
        foreach ($refunds as $refund) {
            if ($refund->isDispatchable()) {
                $this->bus->dispatch(new ProcessRefundMessage(
                    $refund->getId()
                ));
            }
        }
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
