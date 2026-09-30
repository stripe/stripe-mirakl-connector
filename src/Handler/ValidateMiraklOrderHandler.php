<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\ValidateMiraklOrderMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use App\Repository\PaymentMappingRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ValidateMiraklOrderHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    private $stripeClient;

    private $paymentMappingRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        PaymentMappingRepository $paymentMappingRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->paymentMappingRepository = $paymentMappingRepository;
    }

    public function __invoke(ValidateMiraklOrderMessage $message): void
    {
        $ordersByCommercialId = $message->getOrders();

        if (empty($ordersByCommercialId)) {
            return;
        }

        // Prepare order for validation
        $orders = [];
        $paymentMappings = $message->getPaymentMappings();
        foreach ($ordersByCommercialId as $commercialId => $ordersById) {
            if (!isset($paymentMappings[$commercialId]) || !$this->paymentIsValid($commercialId, $ordersById, $paymentMappings[$commercialId])) {
                continue;
            }

            foreach ($ordersById as $order) {
                $orders[] = [
                    'amount' => $order->getAmountDue(),
                    'customer_id' => $order->getCustomerId(),
                    'order_id' => $order->getOrderId(),
                    'payment_status' => 'OK',
                    'transaction_number' => $paymentMappings[$commercialId]->getStripeChargeId(),
                ];
            }
        }

        $this->logger->info('Validate '.count($orders).' Mirakl order(s)');
        if (!empty($orders)) {
            $this->miraklClient->validateProductPendingDebits($orders);
        }
    }

    private function paymentIsValid(string $commercialId, array $orders, PaymentMapping $paymentMapping): bool
    {
        try {
            $charge = $this->retrieveCurrentCharge($paymentMapping);
        } catch (\Throwable $exception) {
            $this->logger->error('Could not retrieve Stripe Charge before Mirakl validation.', [
                'commercial_id' => $commercialId,
                'charge_id' => $paymentMapping->getStripeChargeId(),
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $firstOrder = current($orders);
        if (false === $firstOrder) {
            $this->logger->error('Rejecting Mirakl validation because the commercial order has no pending debits.', [
                'commercial_id' => $commercialId,
                'charge_id' => $paymentMapping->getStripeChargeId(),
            ]);

            return false;
        }

        $stripeCurrency = strtolower((string) ($charge->currency ?? ''));
        $miraklCurrency = strtolower((string) $firstOrder->getCurrency());
        if ('' === $stripeCurrency || '' === $miraklCurrency || $stripeCurrency !== $miraklCurrency) {
            $this->logger->error('Rejecting Mirakl validation because Stripe and Mirakl currencies differ.', [
                'commercial_id' => $commercialId,
                'charge_id' => $paymentMapping->getStripeChargeId(),
                'stripe_currency' => $stripeCurrency,
                'mirakl_currency' => $miraklCurrency,
            ]);

            $this->markPaymentRejected($paymentMapping, 'Stripe and Mirakl currencies differ.');

            return false;
        }

        $expectedAmount = 0;
        foreach ($orders as $order) {
            $orderCurrency = strtolower((string) $order->getCurrency());
            if ($orderCurrency !== $miraklCurrency) {
                $this->markPaymentRejected($paymentMapping, 'Mirakl pending debits have mixed currencies.');
                return false;
            }
            $expectedAmount += $this->toMinorUnits($order->getAmountDue(), $miraklCurrency);
        }

        $chargeAmount = (int) ($charge->amount ?? 0);
        $amountRefunded = (int) ($charge->amount_refunded ?? 0);
        $usableAmount = $chargeAmount - $amountRefunded;
        $chargeStatus = (string) ($charge->status ?? '');
        $disputed = (bool) ($charge->disputed ?? false);
        if ($usableAmount < $expectedAmount || 'succeeded' !== $chargeStatus || $disputed) {
            $this->logger->error('Rejecting Mirakl validation because Stripe payment is not sufficient or usable.', [
                'commercial_id' => $commercialId,
                'charge_id' => $paymentMapping->getStripeChargeId(),
                'expected_amount' => $expectedAmount,
                'usable_amount' => $usableAmount,
                'charge_status' => $chargeStatus,
                'disputed' => $disputed,
            ]);

            $this->markPaymentRejected($paymentMapping, 'Stripe payment is not sufficient or usable.');

            return false;
        }

        $paymentMapping->setStripeAmount($chargeAmount);
        $paymentMapping->setStripeCurrency($stripeCurrency);
        $this->paymentMappingRepository->flush();

        return true;
    }

    private function markPaymentRejected(PaymentMapping $paymentMapping, string $reason): void
    {
        $paymentMapping->setStatus(PaymentMapping::CANCELED);
        $paymentMapping->setStatusReason(PaymentMapping::INVALID_PAYMENT_REASON_PREFIX.' '.$reason);
        $this->paymentMappingRepository->flush();
    }

    private function retrieveCurrentCharge(PaymentMapping $paymentMapping): \Stripe\Charge
    {
        $paymentId = $paymentMapping->getStripeChargeId();
        if (!str_starts_with($paymentId, 'pi_')) {
            return $this->stripeClient->chargeRetrieve($paymentId);
        }

        $paymentIntent = $this->stripeClient->paymentIntentRetrieve($paymentId);
        $latestCharge = $paymentIntent->latest_charge ?? null;
        if ($latestCharge instanceof \Stripe\Charge) {
            return $latestCharge;
        }

        if (is_string($latestCharge) && '' !== $latestCharge) {
            return $this->stripeClient->chargeRetrieve($latestCharge);
        }

        throw new \RuntimeException(sprintf('PaymentIntent %s has no latest Charge.', $paymentId));
    }

    private function toMinorUnits(float $amount, string $currency): int
    {
        // Stripe represents supported presentment currencies in two decimal places unless
        // they are in this zero-decimal list. UGX is intentionally absent: Stripe requires
        // charge amounts in two-decimal representation for backwards compatibility.
        $zeroDecimalCurrencies = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
        $multiplier = in_array($currency, $zeroDecimalCurrencies, true) ? 1 : 100;

        return (int) round($amount * $multiplier);
    }
}
