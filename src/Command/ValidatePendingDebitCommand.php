<?php

namespace App\Command;

use App\Entity\StripePayment;
use App\Message\CapturePendingPaymentMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\StripePaymentRepository;
use App\Utils\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ValidatePendingDebitCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected const ORDER_STATUS_VALIDATED = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED', 'REFUSED', 'CANCELED', 'WAITING_DEBIT_PAYMENT'];
    protected const ORDER_STATUS_TO_CAPTURE = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'WAITING_DEBIT_PAYMENT'];
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
     * @var StripePaymentRepository
     */
    private $stripePaymentRepository;

    public function __construct(
        MessageBusInterface $bus,
        MiraklClient $miraklClient,
        StripePaymentRepository $stripePaymentRepository
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripePaymentRepository = $stripePaymentRepository;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Validate pending order whose  payment can be captured')
            ->setHelp('This command will fetch pending Mirakl order , check if we have payment intent or charge and confirm it on mirakl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        // get mirakl order with pending payment
        $miraklOrderDebits = $this->miraklClient->listPendingPayments();

        if (empty($miraklOrderDebits)) {
            $output->writeln('No mirakl order debits');
            return 0;
        }

        // format Mirakl orders
        $orderIds = [];
        foreach ($miraklOrderDebits as $miraklOrderDebit) {
            $orderId = $miraklOrderDebit['order_id'];
            $commercialOrderId = $miraklOrderDebit['order_commercial_id'];
            $orderIds[] = $commercialOrderId;

            $miraklOrderDebit[$commercialOrderId] = $miraklOrderDebit[$commercialOrderId] ?? [];
            $miraklOrderDebits[$commercialOrderId][$orderId] = $miraklOrderDebit;
        }

        // get stripe known payment intent or charge for pending oder
        $stripePayments = $this->stripePaymentRepository->findPendingPaymentByOrderIds($orderIds);

        // keep orders to validate
        $ordersToValidate = array_intersect_key($miraklOrderDebits, $stripePayments);

        // keep payment for pending orders
        $stripePayments = array_intersect_key($stripePayments, $ordersToValidate);

        // validate payment to mirakl when we have a charge/paymentIntent
        $this->validateOrders($ordersToValidate, $stripePayments);

        // capture payment when mirakl order is totally validate
        $this->capturePendingPayment(array_keys($ordersToValidate), $stripePayments);

        return 0;
    }

    /**
     * @param array $ordersToValidate
     * @param StripePayment[] $stripePayment
     */
    protected function validateOrders(array $ordersToValidate, array $stripePayment): void
    {
        if (empty($ordersToValidate)) {
            $this->logger->info('No mirakl order to validate');
            return;
        }

        $this->bus->dispatch(new ValidateMiraklOrderMessage($ordersToValidate, $stripePayment));
    }

    /**
     * @param array $commercialIds
     * @param array $stripePayments
     */
    protected function capturePendingPayment(array $commercialIds, array $stripePayments): void
    {
        // list all orders with the same commercial id as those previously validated
        $ordersToCapture = $this->miraklClient->listCommercialOrdersById($commercialIds);

        // we keep complete order to capture payment
        $notTotalyValidated = [];
        $orderCanBeCaptured = [];
        foreach ($ordersToCapture as $order) {
            $commercialOrderId = $order['commercial_id'];

            if (in_array($commercialOrderId, $notTotalyValidated, true)) {
                continue;
            }

            $status = $order['order_state'];

            if (!in_array($status, self::ORDER_STATUS_VALIDATED, true)) {
                $notTotalyValidated[] = $commercialOrderId;

                if (isset($orderCanBeCaptured[$commercialOrderId])) {
                    unset($orderCanBeCaptured[$commercialOrderId]);
                }
                continue;
            }

            if (in_array($status, self::ORDER_STATUS_TO_CAPTURE)) {
                $totalPrice = $order['total_price'];

                if (!isset($orderCanBeCaptured[$commercialOrderId])) {
                    $orderCanBeCaptured[$commercialOrderId] = 0;
                }

                $orderCanBeCaptured[$commercialOrderId] += $totalPrice;
            }
        }

        // we capture payment
        foreach ($orderCanBeCaptured as $commercialId => $amount) {
            $this->bus->dispatch(new CapturePendingPaymentMessage($stripePayments[$commercialId], (int)$amount * 100));
        }
    }
}
