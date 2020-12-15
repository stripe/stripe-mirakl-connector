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
    private $stripeChargeRepository;

    public function __construct(
        MessageBusInterface $bus,
        MiraklClient $miraklClient,
        StripeChargeRepository $stripeChargeRepository
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripeChargeRepository = $stripeChargeRepository;

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
        $stripeCharges = $this->stripeChargeRepository->findPendingChargeByOrderIds($orderIds);

        // keep orders to validate
        $ordersToValidate = array_intersect_key($miraklOrderDebits, $stripeCharges);

        // keep payment for pending orders
        $stripeCharges = array_intersect_key($stripeCharges, $ordersToValidate);

        if (empty($ordersToValidate)) {
            $this->logger->info('No mirakl order to validate');
            return;
        }

        $this->bus->dispatch(new ValidateMiraklOrderMessage($ordersToValidate, $stripeCharges));
    }

    /**
     * @param OutputInterface $output
     */
    protected function capturePendingPayment(OutputInterface $output): void
    {
        $stripeCharges = $this->stripeChargeRepository->findPendingPayments();

        if (empty($stripeCharges)) {
            $output->writeln('No payment to capture');
            return;
        }

        $chargesAmountByCommercialIds = [];
        foreach ($stripeCharges as $stripeCharge) {
            $chargesAmountByCommercialIds[$stripeCharge->getMiraklOrderId()] = $stripeCharge->getStripeAmount();
        }

        // list all orders with the same commercial id as those previously validated
        $ordersToCapture = $this->miraklClient->listCommercialOrdersById(array_keys($stripeCharges));

        // we keep complete order to capture payment
        $ignoredCommercialOrderIds = [];
        $amountToCaptureByCommercialOrderId = [];

        foreach ($ordersToCapture as $order) {
            $commercialOrderId = $order['commercial_id'];

            if (in_array($commercialOrderId, $ignoredCommercialOrderIds, true)) {
                continue;
            }

            $status = $order['order_state'];

            // This order is not validated.
            // We need to skip any cpature/cancelling for the full commercial order
            if (!in_array($status, self::ORDER_STATUS_VALIDATED, true)) {
                $ignoredCommercialOrderIds[] = $commercialOrderId;

                if (isset($amountToCaptureByCommercialOrderId[$commercialOrderId])) {
                    unset($amountToCaptureByCommercialOrderId[$commercialOrderId]);
                }

                continue;
            }

            $orderAmount = gmp_intval((string) ($order['total_price'] * 100));

            if (!isset($amountToCaptureByCommercialOrderId[$commercialOrderId])) {
                $amountToCaptureByCommercialOrderId[$commercialOrderId] = $chargesAmountByCommercialIds[$commercialOrderId];
            }

            if (!in_array($status, self::ORDER_STATUS_TO_CAPTURE)) {
                // This order is not to be fully captured.
                // We decrement the total amount to capture
                $amountToCaptureByCommercialOrderId[$commercialOrderId] -= $orderAmount;
            }
        }

        foreach ($amountToCaptureByCommercialOrderId as $commercialId => $commercialOrderAmount) {
            if ($commercialOrderAmount > 0) {
                $this->bus->dispatch(new CapturePendingPaymentMessage($stripeCharges[$commercialId]->getId(), $commercialOrderAmount));
            } else {
                // All orders have been refused/cancelled: cancel capture
                $this->bus->dispatch(new CancelPendingPaymentMessage($stripeCharges[$commercialId]->getId(), $chargesAmountByCommercialIds[$commercialId]));
            }
        }
    }
}
