<?php

namespace App\Handler;

use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripeChargeRepository;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CapturePendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeChargeRepository
     */
    private $stripePaymentRepository;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(
        StripeProxy $stripeProxy,
        StripeChargeRepository $stripePaymentRepository
    ) {
        $this->stripeProxy = $stripeProxy;
        $this->stripePaymentRepository = $stripePaymentRepository;
    }

    public function __invoke(CapturePendingPaymentMessage $message)
    {
        $stripePaymentId = $message->getStripePaymentId();

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        if (null === $stripePayment) {
            return;
        }

        try {
            $this->stripeProxy->capture($stripePayment->getStripeChargeId(), $message->getAmount());
            $stripePayment->capture();
            $this->stripePaymentRepository->persistAndFlush($stripePayment);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Payment: %s.', $e->getMessage()), [
                'paymentId' => $stripePayment->getStripeChargeId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
