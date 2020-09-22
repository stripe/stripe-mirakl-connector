<?php

namespace App\Command;

use App\Repository\StripePaymentRepository;
use App\Utils\MiraklClient;
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

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripePaymentRepository
     */
    private $stripePaymentRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripePaymentRepository $stripePaymentRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripePaymentRepository = $stripePaymentRepository;

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
        foreach ($ordersToValidate as $orderToValidate) {
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

        return 0;
    }


}
