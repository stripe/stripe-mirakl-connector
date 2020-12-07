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

        $amountToCaptureByCommercialOrderId = [];
        foreach ($stripePayments as $stripePayment) {
            $commercialOrderId = $stripePayment->getMiraklOrderId();

            if (!isset($amountToCaptureByCommercialOrderId[$commercialOrderId])) {
                $stripeAmount = $stripePayment->getStripeAmount();
                $amountToCaptureByCommercialId[$commercialOrderId] = $stripeAmount * 100;
            }
        }

        // list all orders with the same commercial id as those previously validated
        $ordersToCapture = $this->miraklClient->listCommercialOrdersById(array_keys($stripePayments));

        // we keep complete order to capture payment
        $ignoredCommercialOrderIds = [];
        $amountToCancelByCommercialOrderId = [];

        foreach ($ordersToCapture as $order) {
            $commercialOrderId = $order['commercial_id'];

            if (in_array($commercialOrderId, $ignoredCommercialOrderIds, true)) {
                continue;
            }

            $status = $order['order_state'];

            if (!in_array($status, self::ORDER_STATUS_VALIDATED, true)) {
                $ignoredCommercialOrderIds[] = $commercialOrderId;

                if (isset($amountToCaptureByCommercialOrderId[$commercialOrderId])) {
                    unset($amountToCaptureByCommercialOrderId[$commercialOrderId]);
                }

                if (isset($amountToCancelByCommercialOrderId[$commercialOrderId])) {
                    unset($amountToCancelByCommercialOrderId[$commercialOrderId]);
                }

                continue;
            }

            $orderAmount = gmp_intval((string) ($order['total_price'] * 100));

            if (!in_array($status, self::ORDER_STATUS_TO_CAPTURE)) {
                if (isset($amountToCaptureByCommercialOrderId[$commercialOrderId])) {
                    $amountToCaptureByCommercialOrderId[$commercialOrderId] -= $orderAmount;
                }

                if (!isset($amountToCancelByCommercialOrderId[$commercialOrderId])) {
                    $amountToCancelByCommercialOrderId[$commercialOrderId] = 0;
                }
                $amountToCancelByCommercialOrderId[$commercialOrderId] += $orderAmount;
            }
        }

        // capture payment
        if (!empty($amountToCaptureByCommercialOrderId)) {
            foreach ($amountToCaptureByCommercialOrderId as $commercialId => $commercialOrderAmount) {
                $this->bus->dispatch(new CapturePendingPaymentMessage($stripePayments[$commercialId]->getId(), $commercialOrderAmount));
            }
        }

        // cancel payment
        foreach ($amountToCancelByCommercialOrderId as $commercialId => $commercialOrderAmount) {
            $this->bus->dispatch(new CancelPendingPaymentMessage($stripePayments[$commercialId]->getId(), $commercialOrderAmount));
        }
    }
}
