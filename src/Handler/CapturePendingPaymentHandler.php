<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CapturePendingPaymentHandler implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    public function __construct(
        StripeClient $stripeClient,
        PaymentMappingRepository $paymentMappingRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->paymentMappingRepository = $paymentMappingRepository;
    }

    public function __invoke(CapturePendingPaymentMessage $message): void
    {
        $paymentMapping = $this->paymentMappingRepository->findOneBy([
            'id' => $message->getPaymentMappingId(),
        ]);
        if (
            null === $paymentMapping
            || !in_array($paymentMapping->getStatus(), [PaymentMapping::TO_CAPTURE, PaymentMapping::CAPTURE_FAILED, PaymentMapping::CANCEL_FAILED], true)
            || (
                null !== $message->getAttempt()
                && $message->getAttempt() !== $paymentMapping->getStripeRequestAttempt()
            )
        ) {
            $this->logger->info('Ignoring stale payment capture message.', [
                'payment_mapping_id' => $message->getPaymentMappingId(),
                'status' => $paymentMapping?->getStatus(),
            ]);

            return;
        }

        try {
            $stripeChargeId = $paymentMapping->getStripeChargeId();
            if (str_starts_with($stripeChargeId, 'pi_')) {
                $paymentIntent = $this->stripeClient->paymentIntentRetrieve($stripeChargeId);
                if ('succeeded' === $paymentIntent->status) {
                    $paymentMapping->capture();
                } else {
                    $this->stripeClient->capturePayment(
                        $stripeChargeId,
                        $message->getAmount(),
                        sprintf(
                            'mirakl-payment-capture-%s-attempt-%d',
                            $paymentMapping->getId(),
                            $paymentMapping->getStripeRequestAttempt()
                        )
                    );
                    $paymentMapping->capture();
                }
            } else {
                $charge = $this->stripeClient->chargeRetrieve($stripeChargeId);
                if ($charge->captured) {
                    $paymentMapping->capture();
                } else {
                    $this->stripeClient->capturePayment(
                        $stripeChargeId,
                        $message->getAmount(),
                        sprintf(
                            'mirakl-payment-capture-%s-attempt-%d',
                            $paymentMapping->getId(),
                            $paymentMapping->getStripeRequestAttempt()
                        )
                    );
                    $paymentMapping->capture();
                }
            }
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $paymentMapping->setStatus(PaymentMapping::CAPTURE_FAILED);
            $paymentMapping->setStatusReason(substr($e->getMessage(), 0, 1024));
            if (!$this->stripeClient->isAmbiguousStripeFailure($e)) {
                $paymentMapping->startNewStripeRequestAttempt();
            }
        }

        $this->paymentMappingRepository->flush();
    }
}
