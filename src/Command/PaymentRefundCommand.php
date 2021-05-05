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
    private $enableProductPaymentRefund;

    /**
     * @var bool
     */
    private $enableServicePaymentRefund;

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
        bool $enableProductPaymentRefund,
        bool $enableServicePaymentRefund
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->paymentRefundService = $paymentRefundService;
        $this->enableProductPaymentRefund = $enableProductPaymentRefund;
        $this->enableServicePaymentRefund = $enableServicePaymentRefund;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger->info('starting');
        if ($this->enableProductPaymentRefund || $this->enableServicePaymentRefund) {
            $this->processBacklog();

            if ($this->enableProductPaymentRefund) {
                $this->processPendingRefunds(MiraklClient::ORDER_TYPE_PRODUCT);
            }

            if ($this->enableServicePaymentRefund) {
                $this->processPendingRefunds(MiraklClient::ORDER_TYPE_SERVICE);
            }
        }

        $this->logger->info('job succeeded');
        return 0;
    }

    private function processBacklog()
    {
        $this->logger->info("Processing backlog.");
        $backlog = $this->paymentRefundService->getRetriableTransfers();
        if (empty($backlog)) {
            $this->logger->info("No backlog.");
            return;
        }

        $this->dispatchTransfers(
            $this->paymentRefundService->updateTransfers($backlog)
        );
    }

    private function processPendingRefunds(string $orderType): void
    {
        $this->logger->info("Processing $orderType pending refunds.");
        $method = "list{$orderType}PendingRefunds";
        $orderRefunds = $this->miraklClient->$method();
        if (empty($orderRefunds)) {
            $this->logger->info("No $orderType pending refund.");
            return;
        }

        $this->dispatchRefunds($this->paymentRefundService->getRefundsFromOrderRefunds($orderRefunds));
        $this->dispatchTransfers($this->paymentRefundService->getTransfersFromOrderRefunds($orderRefunds));
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
