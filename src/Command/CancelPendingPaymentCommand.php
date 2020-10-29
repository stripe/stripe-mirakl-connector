<?php

namespace App\Command;

use App\Message\CancelPendingPaymentMessage;
use App\Repository\StripePaymentRepository;
use App\Utils\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class CancelPendingPaymentCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:cancel:pending-debit';
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
            ->setDescription('Cancel not captured payments when order was refused')
            ->setHelp('This command will fetch refused Mirakl orders whose not paid and cancel not yet captured payment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        // get payments whose waiting to be capture
        $stripePayments = $this->stripePaymentRepository->findPendingPayments();

        if (empty($stripePayments)) {
            $output->writeln('No payment waiting to be captured');
            return 0;
        }

        // list all orders with payments whose waiting to be capture
        $orders = $this->miraklClient->listCommercialOrdersById(array_keys($stripePayments));

        $ordersToCancel = [];
        $amounts = [];

        foreach ($orders as $order) {
            $commercialOrderId = $order['commercial_id'];

            $isRefused = $order['order_state'] === 'REFUSED';
            $amount = gmp_intval((string) ($order['total_price'] * 100));

            if (isset($ordersToCancel[$commercialOrderId])) {
                $ordersToCancel[$commercialOrderId] &= $isRefused;
                $amounts[$commercialOrderId] += $amount;
            } else {
                $ordersToCancel[$commercialOrderId] = $isRefused;
                $amounts[$commercialOrderId] = $amount;
            }
        }

        // we capture payment
        foreach ($ordersToCancel as $commercialId => $isTotallyRefused) {
            if ($isTotallyRefused) {
                $this->bus->dispatch(new CancelPendingPaymentMessage($stripePayments[$commercialId]->getId(), $amounts[$commercialId]));
            }
        }

        return 0;
    }
}
