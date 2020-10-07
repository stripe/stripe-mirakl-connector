<?php

namespace App\Command;

use App\Entity\StripeRefund;
use App\Message\ProcessRefundMessage;
use App\Repository\StripeRefundRepository;
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
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    public function __construct(MessageBusInterface $bus, MiraklClient $miraklClient, StripeRefundRepository $stripeRefundRepository, bool $enablesAutoRefundCreation)
    {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripeRefundRepository = $stripeRefundRepository;
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
                    $stripeRefund = $this->stripeRefundRepository->findOneBy([
                        'miraklRefundId' => $refund['id'],
                    ]);

                    if (is_null($stripeRefund)) {
                        $newlyCreatedStripeRefund = $this->createStripeRefund($refund, $currency, $miraklOrder);
                        $message = new ProcessRefundMessage($newlyCreatedStripeRefund->getMiraklRefundId());
                        $this->bus->dispatch($message);
                    }
                }
            }
        }
    }

    private function createStripeRefund(array $refund, string $currency, array $miraklOrder)
    {
        $newlyCreatedStripeRefund = new StripeRefund();
        try {
            $newlyCreatedStripeRefund
                ->setAmount($refund['amount'] * 100)
                ->setCurrency($currency)
                ->setMiraklRefundId($refund['id'])
                ->setMiraklOrderId($miraklOrder['order_id'])
                ->setStatus(StripeRefund::REFUND_PENDING);
            $this->stripeRefundRepository->persistAndFlush($newlyCreatedStripeRefund);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->info('Mirakl refund already exists', [
                'miraklOrder' => $miraklOrder['order_id'],
                'miraklRefund' => $refund['id'],
            ]);
        }
        assert(null !== $newlyCreatedStripeRefund->getId());

        return $newlyCreatedStripeRefund;
    }
}
