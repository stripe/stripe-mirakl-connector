<?php

namespace App\Command;

use App\Entity\StripeCharge;
use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\StripeChargeRepository;
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

    protected const ORDER_STATUS_VALIDATED = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED', 'REFUSED', 'CANCELED'];
    protected const ORDER_STATUS_TO_CAPTURE = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED'];
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
     * @var StripeChargeRepository
     */
    private $stripePaymentRepository;

    public function __construct(
        MessageBusInterface $bus,
        MiraklClient $miraklClient,
        StripeChargeRepository $stripePaymentRepository
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

        // validate payment to mirakl when we have a charge/paymentIntent
        $this->validateOrders($output);

        // capture payment when mirakl order is totally validate
        $this->capturePendingPayment($output);

        return 0;
    }

    /**
     * @param OutputInterface $output
     */
    protected function validateOrders(OutputInterface $output): void
    {
        // get mirakl order with pending payment
        $miraklOrderDebits = $this->miraklClient->listPendingPayments();

        if (empty($miraklOrderDebits)) {
            $output->writeln('No mirakl order debits');
            return;
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
        $stripePayments = $this->stripePaymentRepository->findPendingChargeByOrderIds($orderIds);

        // keep orders to validate
        $ordersToValidate = array_intersect_key($miraklOrderDebits, $stripePayments);

        // keep payment for pending orders
        $stripePayments = array_intersect_key($stripePayments, $ordersToValidate);

        if (empty($ordersToValidate)) {
            $this->logger->info('No mirakl order to validate');
            return;
        }

        $this->bus->dispatch(new ValidateMiraklOrderMessage($ordersToValidate, $stripePayments));
    }

    /**
     * @param OutputInterface $output
     */
    protected function capturePendingPayment(OutputInterface $output): void
    {
        $stripePayments = $this->stripePaymentRepository->findPendingPayments();

        if (empty($stripePayments)) {
            $output->writeln('No payment to capture');
            return;
        }

        // list all orders with the same commercial id as those previously validated
        $ordersToCapture = $this->miraklClient->listCommercialOrdersById(array_keys($stripePayments));

        // we keep complete order to capture payment
        $notTotalyValidated = [];
        $orderCanBeCaptured = [];
        $orderCanCancelPayment = [];
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

                if (isset($orderCanCancelPayment[$commercialOrderId])) {
                    unset($orderCanCancelPayment[$commercialOrderId]);
                }

                continue;
            }

            $amount = gmp_intval((string) ($order['total_price'] * 100));

            if (in_array($status, self::ORDER_STATUS_TO_CAPTURE)) {
                if (!isset($orderCanBeCaptured[$commercialOrderId])) {
                    $orderCanBeCaptured[$commercialOrderId] = 0;
                }

                $orderCanBeCaptured[$commercialOrderId] += $amount;
            } else {
                // can be partially Captured, no need to cancel payment
                if (isset($orderCanBeCaptured[$commercialOrderId])) {
                    continue;
                }

                if (!isset($orderCanCancelPayment[$commercialOrderId])) {
                    $orderCanCancelPayment[$commercialOrderId] = 0;
                }

                $orderCanCancelPayment[$commercialOrderId] += $amount;
            }
        }

        // capture payment
        foreach ($orderCanBeCaptured as $commercialId => $amount) {
            $this->bus->dispatch(new CapturePendingPaymentMessage($stripePayments[$commercialId]->getId(), $amount));
        }

        // cancel payment
        foreach ($orderCanCancelPayment as $commercialId => $amount) {
            $this->bus->dispatch(new CancelPendingPaymentMessage($stripePayments[$commercialId]->getId(), $amount));
        }
    }
}
