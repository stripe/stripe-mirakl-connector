<?php

namespace App\Handler;

use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripeChargeRepository;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CancelPendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeChargeRepository
     */
    private $stripeChargeRepository;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(
        StripeProxy $stripeProxy,
        StripeChargeRepository $stripeChargeRepository
    ) {
        $this->stripeProxy = $stripeProxy;
        $this->stripeChargeRepository = $stripeChargeRepository;
    }

    public function __invoke(CancelPendingPaymentMessage $message)
    {
        $stripeChargeId = $message->getstripeChargeId();

        $stripeCharge = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        if (null === $stripeCharge) {
            return;
        }

        try {
            $this->stripeProxy->cancelBeforeCapture($stripeCharge->getStripeChargeId(), $message->getAmount());
            $stripeCharge->cancel();
            $this->stripeChargeRepository->persistAndFlush($stripeCharge);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not cancel Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $stripeCharge->getStripeChargeId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
