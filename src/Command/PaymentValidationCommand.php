<?php

namespace App\Command;

use App\Entity\MiraklOrder;
use App\Entity\MiraklProductOrder;
use App\Entity\MiraklServiceOrder;
use App\Entity\PaymentMapping;
use App\Exception\InvalidArgumentException;
use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\MiraklClient;
use App\Service\StripeAmount;
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
        $commercialIds = array_keys($paymentMappings);
        $productOrdersByCommercialId = $this->miraklClient->listProductOrdersByCommercialId($commercialIds);
        $serviceOrdersByCommercialId = $this->miraklClient->listServiceOrdersByCommercialId($commercialIds);
        $ordersByCommercialId = [];
        foreach ($commercialIds as $commercialId) {
            $orders = array_merge(
                array_values($productOrdersByCommercialId[$commercialId] ?? []),
                array_values($serviceOrdersByCommercialId[$commercialId] ?? [])
            );
            if (!empty($orders)) {
                $ordersByCommercialId[$commercialId] = $orders;
            }
        }

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
            $currency = $paymentMapping->getStripeCurrency();
            if (null === $captureAmount || null === $currency) {
                $this->logger->error('Skipping payment capture because the validated Stripe amount or currency is missing.', [
                    'commercial_id' => $commercialId,
                ]);
                continue;
            }

            $hasAcceptedOrder = false;
            $acceptedAmount = 0;
            foreach ($ordersByCommercialId[$commercialId] as $order) {
                $orderId = $order->getId();
                if (strtolower($order->getCurrency()) !== $currency) {
                    $this->logger->error('Skipping payment capture because Mirakl and Stripe currencies differ.', [
                        'commercial_id' => $commercialId,
                        'order_id' => $orderId,
                    ]);
                    continue 2;
                }
                if (!$this->pa01AfterCapture) {
                    $success  = $this->captureValidatedOrder($order, $commercialId, $orderId);
                } else {
                    $success = $this->captureWaitingOrder($order, $commercialId, $orderId);
                }
                if (!$success) {
                    continue 2;
                }

                if (!$order->isAborted()) {
                    $hasAcceptedOrder = true;
                    try {
                        $orderAmount = StripeAmount::toMinorUnits($order->getAmountDue(), $currency);
                        if ($orderAmount <= 0) {
                            throw new InvalidArgumentException('Mirakl accepted order amount must be positive.');
                        }
                        $acceptedAmount += $orderAmount;
                    } catch (InvalidArgumentException $e) {
                        $this->logger->error('Skipping payment capture because an accepted order amount is invalid.', [
                            'commercial_id' => $commercialId,
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                        continue 2;
                    }
                }

                // Deduct refused or canceled orders from the aggregate capture amount.
                $abortedAmount = $order->getAbortedAmount();
                if ($abortedAmount > 0) {
                    $this->logger->info(
                        "Deducting order aborted amount {$abortedAmount} from capture amount.",
                        ['commercial_id' => $commercialId, 'order_id' => $orderId]
                    );
                    try {
                        $captureAmount -= StripeAmount::toMinorUnits($abortedAmount, $currency);
                    } catch (InvalidArgumentException $e) {
                        $this->logger->error('Skipping payment capture because the aborted amount is invalid.', [
                            'commercial_id' => $commercialId,
                            'order_id' => $orderId,
                            'error' => $e->getMessage(),
                        ]);
                        continue 2;
                    }
                }
            }

            if ($hasAcceptedOrder && ($captureAmount <= 0 || $captureAmount < $acceptedAmount)) {
                $this->logger->error('Skipping payment capture because the amount does not cover accepted orders.', [
                    'commercial_id' => $commercialId,
                    'capture_amount' => $captureAmount,
                    'accepted_amount' => $acceptedAmount,
                ]);
                continue;
            }

            $this->dispatchCaptureMessage($paymentMapping, $captureAmount);
        }
    }

    private function captureValidatedOrder(MiraklOrder $order, string $commercialId, string $orderId): bool
    {
        if ($order instanceof MiraklServiceOrder) {
            return $this->captureWaitingOrder($order, $commercialId, $orderId);
        }

        // Order must be validated (accepted/refused)
        if (!$order->isValidated()) {
            $this->logger->info(
                'Skipping payment capture for non-accepted logistical order.',
                ['commercial_id' => $commercialId, 'order_id' => $orderId]
            );
            return false;
        }

        // Payment must be validated (via PA01) if the order wasn't aborted
        if (!$order->isAborted() && $order instanceof MiraklProductOrder && !$order->isPaid()) {
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
