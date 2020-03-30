<?php

namespace App\Command;

use App\Entity\StripeTransfer;
use App\Entity\MiraklRefund;
use App\Message\ProcessTransferMessage;
use App\Message\ProcessRefundMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripeTransferRepository;
use App\Repository\MiraklRefundRepository;
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

    /**
     * @var MiraklRefundRepository
     */
    private $miraklRefundRepository;

    public function __construct(MessageBusInterface $bus, bool $enablesAutoTransferCreation, string $orderPaymentType, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripeTransferRepository $stripeTransferRepository, MiraklStripeMappingRepository $miraklStripeMappingRepository, MiraklRefundRepository $miraklRefundRepository)
    {
        $this->bus = $bus;
        $this->enablesAutoTransferCreation = $enablesAutoTransferCreation;
        $this->orderPaymentType = $orderPaymentType;
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
        $this->miraklRefundRepository = $miraklRefundRepository;
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
            // Retrieve transfer to get the charge id
            $currency = $miraklOrder['currency_iso_code'];

            foreach ($miraklOrder['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $refund) {
                    $miraklRefund = $this->miraklRefundRepository->findOneBy([
                        'miraklRefundId' => $refund['id']
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

    private function createMiraklRefund(array $refund, string $currency, array $miraklOrder) {
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
                'miraklRefund' => $refund['id']
            ]);
        }
        assert(null !== $newlyCreatedMiraklRefund->getId());
        return $newlyCreatedMiraklRefund;
    }
}
