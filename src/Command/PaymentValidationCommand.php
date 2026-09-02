<?php

namespace App\Command;

use App\Entity\MiraklOrder;
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
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'connector:validate:pending-debit')]
class PaymentValidationCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public function __construct(
        private MessageBusInterface $bus,
        private MiraklClient $miraklClient,
        private PaymentMappingRepository $paymentMappingRepository,
        private bool $pa01AfterCapture
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate pending order whose payment can be captured')
            ->setHelp('This command will fetch pending Mirakl orders, check if we have payment intent or charge and confirm it on mirakl');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->info('starting');
        // validate payment to mirakl when we have a charge/paymentIntent
        $ordersByCommercialId = $this->miraklClient->listProductPendingDebits();
        if (!empty($ordersByCommercialId)) {
            $this->validateOrders($ordersByCommercialId);
        } else {
            $this->logger->info('No mirakl orders pending debit');
        }

        // capture payment when mirakl order is totally validated
        $paymentMappings = $this->paymentMappingRepository->findToCapturePayments();
        if (!empty($paymentMappings)) {
            $this->capturePayments($paymentMappings);
        } else {
            $this->logger->info('No payment to capture');
        }

        $this->logger->info('job succeeded');

        return 0;
    }

    protected function validateOrders(array $ordersByCommercialId): void
    {
        // get stripe known payment intent or charge for pending order
        $paymentMappings = $this->paymentMappingRepository->findPaymentsByCommercialOrderIdsAndStatuses(
            array_keys($ordersByCommercialId),
            !$this->pa01AfterCapture ? [PaymentMapping::CAPTURED, PaymentMapping::TO_CAPTURE] : [PaymentMapping::CAPTURED]
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

    protected function capturePayments(array $paymentMappings): void
    {
        // List all orders using the provided commercial IDs
        $ordersByCommercialId = $this->miraklClient->listProductOrdersByCommercialId(
            array_keys($paymentMappings)
        );

        // Calculate the right amount to be captured for each commercial order
        foreach ($paymentMappings as $commercialId => $paymentMapping) {
            if (!isset($ordersByCommercialId[$commercialId])) {
                $this->logger->info(
                    'Skipping payment capture for non-existing commercial order.',
                    ['commercial_id' => $commercialId]
                );
                continue;
            }

            $captureAmount = $paymentMapping->getStripeAmount();
            foreach ($ordersByCommercialId[$commercialId] as $orderId => $order) {
                if (!$this->pa01AfterCapture) {
                    $success  = $this->captureValidatedOrder($order, $commercialId, $orderId);
                } else {
                    $success = $this->captureWaitingOrder($order, $commercialId, $orderId);
                }
                if (!$success) {
                    continue 2;
                }

                // Deduct refused or canceled orders from the aggregate capture amount.
                $abortedAmount = $order->getAbortedAmount();
                if ($abortedAmount > 0) {
                    $this->logger->info(
                        "Deducting order aborted amount {$abortedAmount} from capture amount.",
                        ['commercial_id' => $commercialId, 'order_id' => $orderId]
                    );
                    $captureAmount -= gmp_intval((string) ($abortedAmount * 100));
                }
            }

            $this->dispatchCaptureMessage($paymentMapping, $captureAmount);
        }
    }

    private function captureValidatedOrder(MiraklOrder $order, string $commercialId, string $orderId): bool
    {
        // Order must be validated (accepted/refused)
        if (!$order->isValidated()) {
            $this->logger->info(
                'Skipping payment capture for non-accepted logistical order.',
                ['commercial_id' => $commercialId, 'order_id' => $orderId]
            );
            return false;
        }

        // Payment must be validated (via PA01) if the order wasn't aborted
        if (!$order->isAborted() && !$order->isPaid()) {
            $this->logger->info(
                'Skipping payment capture for non-validated logistical order payment.',
                ['commercial_id' => $commercialId, 'order_id' => $orderId]
            );
            return false;
        }

        return true;
    }

    private function captureWaitingOrder(MiraklOrder $order, string $commercialId, string $orderId): bool
    {
        // Order must be in an accepted state
        if (!$order->isAccepted()) {
            $this->logger->info(
                'Skipping payment capture for non-accepted logistical order.',
                ['commercial_id' => $commercialId, 'order_id' => $orderId]
            );
            return false;
        }
        return true;
    }

    private function dispatchCaptureMessage(PaymentMapping $paymentMapping, int $captureAmount): void
    {
        // Capture or cancel payment
        $mappingId = $paymentMapping->getId();
        if ($captureAmount > 0) {
            $message = new CapturePendingPaymentMessage($mappingId, $captureAmount);
        } else {
            $message = new CancelPendingPaymentMessage($mappingId);
        }

        $this->bus->dispatch($message);
    }
}
