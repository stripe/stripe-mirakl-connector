<?php

namespace App\Command;

use App\Repository\StripePaymentRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ValidatePendingDebitCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:validate:pending-debit';
    protected const ORDER_STATUS_VALIDATED = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'CLOSED', 'REFUSED', 'CANCELED', 'WAITING_DEBIT_PAYMENT'];
    protected const ORDER_STATUS_TO_CAPTURE = ['SHIPPING', 'SHIPPED', 'TO_COLLECT', 'RECEIVED', 'WAITING_DEBIT_PAYMENT'];

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripePaymentRepository
     */
    private $stripePaymentRepository;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(
        MiraklClient $miraklClient,
        StripeProxy $stripeProxy,
        StripePaymentRepository $stripePaymentRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripePaymentRepository = $stripePaymentRepository;
        $this->stripeProxy = $stripeProxy;

        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setDescription('Validate pending order whose  payment can be captured')
            ->setHelp('This command will fetch pending Mirakl order , check if we have payment intent or charge and confirm it on mirakl')
    ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        // get mirakl order with pending payment
        $miraklOrderDebits = $this->miraklClient->listPendingPayments();

        if (empty($miraklOrderDebits)) {
            $output->writeln('No mirakl order debits');
            return 0;
        }

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

        if (empty($ordersToValidate)) {
            $output->writeln('No mirakl order to validate');
            return 0;
        }

        // Prepare order for validation
        $orders = [];
        $orderCommercialIds = [];
        foreach ($ordersToValidate as $orderCommercialId => $orderToValidate) {
            $orderCommercialIds[] = $orderCommercialId;
            foreach ($orderToValidate as $order) {
                $orders[] = [
                    'amount' => $order['amount'],
                    'order_id' => $order['order_id'],
                    'customer_id' => $order['customer_id'],
                    'payment_status' => 'OK',
                ];
            }
        }

        // Validate payment on order
        $this->miraklClient->validatePayments($orders);

        // list all orders with the same commercial id as those previously validated
        $ordersToCapture = $this->miraklClient->listCommercialOrdersById($orderCommercialIds);

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
            $paymentId = $stripePayments[$commercialId]->getStripePaymentId();
            $this->stripeProxy->capture($paymentId, $amount * 100);
        }

        return 0;
    }
}
