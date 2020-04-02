<?php

namespace App\Command;

use App\Entity\MiraklRefund;
use App\Message\ProcessRefundMessage;
use App\Repository\MiraklRefundRepository;
use App\Utils\MiraklClient;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
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
    private $enablesAutoRefundCreation;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var MiraklRefundRepository
     */
    private $miraklRefundRepository;

    public function __construct(MessageBusInterface $bus, MiraklClient $miraklClient, MiraklRefundRepository $miraklRefundRepository, bool $enablesAutoRefundCreation)
    {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->miraklRefundRepository = $miraklRefundRepository;
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

        // Fetch new refunds
        $miraklOrders = $this->miraklClient->listPendingRefunds();
        $this->processRefunds($miraklOrders);

        return 0;
    }

    private function processRefunds(array $miraklOrders): void
    {
        if (0 === count($miraklOrders)) {
            return;
        }

        foreach ($miraklOrders as $miraklOrder) {
            $currency = $miraklOrder['currency_iso_code'];
            foreach ($miraklOrder['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $refund) {
                    $miraklRefund = $this->miraklRefundRepository->findOneBy([
                        'miraklRefundId' => $refund['id'],
                    ]);

                    if (is_null($miraklRefund)) {
                        $newlyCreatedMiraklRefund = $this->createMiraklRefund($refund, $currency, $miraklOrder);
                        $message = new ProcessRefundMessage($newlyCreatedMiraklRefund->getMiraklRefundId());
                        $this->bus->dispatch($message);
                    }
                }
            }
        }
    }

    private function createMiraklRefund(array $refund, string $currency, array $miraklOrder)
    {
        $newlyCreatedMiraklRefund = new MiraklRefund();
        try {
            $newlyCreatedMiraklRefund
                ->setAmount($refund['amount'] * 100)
                ->setCurrency($currency)
                ->setMiraklRefundId($refund['id'])
                ->setMiraklOrderId($miraklOrder['order_id'])
                ->setStatus(MiraklRefund::REFUND_PENDING);
            $this->miraklRefundRepository->persistAndFlush($newlyCreatedMiraklRefund);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->info('Mirakl refund already exists', [
                'miraklOrder' => $miraklOrder['order_id'],
                'miraklRefund' => $refund['id'],
            ]);
        }
        assert(null !== $newlyCreatedMiraklRefund->getId());

        return $newlyCreatedMiraklRefund;
    }
}
