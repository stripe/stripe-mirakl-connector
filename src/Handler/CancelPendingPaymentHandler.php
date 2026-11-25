<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\CancelPendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CancelPendingPaymentHandler implements LoggerAwareInterface
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

    public function __invoke(CancelPendingPaymentMessage $message): void
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
            $this->logger->info('Ignoring stale payment cancellation message.', [
                'payment_mapping_id' => $message->getPaymentMappingId(),
                'status' => $paymentMapping?->getStatus(),
            ]);

            return;
        }

        try {
            $this->stripeClient->cancelPayment(
                $paymentMapping->getStripeChargeId(),
                sprintf(
                    'mirakl-payment-cancel-%s-attempt-%d',
                    $paymentMapping->getId(),
                    $paymentMapping->getStripeRequestAttempt()
                )
            );
            $paymentMapping->cancel();
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not cancel Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $paymentMapping->setStatus(PaymentMapping::CANCEL_FAILED);
            $paymentMapping->setStatusReason(substr($e->getMessage(), 0, 1024));
            if (!$this->stripeClient->isAmbiguousStripeFailure($e)) {
                $paymentMapping->startNewStripeRequestAttempt();
            }
        }

        $this->paymentMappingRepository->flush();
    }
}
