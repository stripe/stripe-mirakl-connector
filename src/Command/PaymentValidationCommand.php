<?php

namespace App\Command;

use App\Entity\PaymentMapping;
use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class PaymentValidationCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const ORDER_STATUS_VALIDATED = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED', 'REFUSED', 'CANCELED'];
    protected const ORDER_STATUS_TO_CAPTURE = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED'];
    protected static $defaultName = 'connector:validate:pending-debit';
    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    public function __construct(
        MessageBusInterface $bus,
        MiraklClient $miraklClient,
        PaymentMappingRepository $paymentMappingRepository
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->paymentMappingRepository = $paymentMappingRepository;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Validate pending order whose payment can be captured')
            ->setHelp('This command will fetch pending Mirakl orders, check if we have payment intent or charge and confirm it on mirakl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        // validate payment to mirakl when we have a charge/paymentIntent
        $ordersByCommercialId = $this->miraklClient->listProductPendingDebits();
        if (!empty($ordersByCommercialId)) {
            $this->validateOrders($ordersByCommercialId);
        } else {
            $output->writeln('No mirakl orders pending debit');
        }

        // capture payment when mirakl order is totally validated
        $paymentMappings = $this->paymentMappingRepository->findToCapturePayments();
        if (!empty($paymentMappings)) {
            $this->capturePayments($paymentMappings);
        } else {
            $output->writeln('No payment to capture');
        }

        return 0;
    }

    /**
     * @param array $ordersByCommercialId
     */
    protected function validateOrders(array $ordersByCommercialId): void
    {
        // get stripe known payment intent or charge for pending order
        $paymentMappings = $this->paymentMappingRepository->findPaymentsByOrderIds(
            array_keys($ordersByCommercialId)
        );

        // Keep orders with a payment mapping and vice versa
        $readyForValidation = array_intersect_key($ordersByCommercialId, $paymentMappings);
        $paymentMappings = array_intersect_key($paymentMappings, $readyForValidation);

        if (empty($readyForValidation)) {
            $this->logger->info('No mirakl order to validate');
            return;
        }

        $this->bus->dispatch(new ValidateMiraklOrderMessage(
            $readyForValidation,
            $paymentMappings
        ));
    }

    /**
     * @param array $paymentMappings
     */
    protected function capturePayments(array $paymentMappings): void
    {
        // Amount is initially set to the authorized amount
        $amountByCommercialId = [];
        foreach ($paymentMappings as $commercialId => $paymentMapping) {
            $amountByCommercialId[$commercialId] = $paymentMapping->getStripeAmount();
        }

        // List all orders with the same commercial id as those previously validated
        $ordersByCommercialId = $this->miraklClient->listProductOrdersByCommercialId(
            array_keys($paymentMappings)
        );

        // Calculate the right amount to be captured
        foreach ($ordersByCommercialId as $commercialId => $ordersById) {
            foreach ($ordersById as $orderId => $order) {
                // This order is not fully validated yet
                if (!in_array($order['order_state'], self::ORDER_STATUS_VALIDATED)) {
                    unset($amountByCommercialId[$commercialId]);
                    continue 2;
                }

                // Deduct refused or canceled orders
                if (!in_array($order['order_state'], self::ORDER_STATUS_TO_CAPTURE)) {
                    $amountToDeduct = gmp_intval((string) ($order['total_price'] * 100));
                    $amountByCommercialId[$commercialId] -= $amountToDeduct;
                }
            }
        }

        foreach ($amountByCommercialId as $commercialId => $finalAmount) {
            if ($finalAmount > 0) {
                $this->bus->dispatch(new CapturePendingPaymentMessage(
                    $paymentMappings[$commercialId]->getId(),
                    $finalAmount
                ));
            } else {
                $this->bus->dispatch(new CancelPendingPaymentMessage(
                    $paymentMappings[$commercialId]->getId()
                ));
            }
        }
    }
}
