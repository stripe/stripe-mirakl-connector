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

    public function __invoke(CapturePendingPaymentMessage $message)
    {
        $stripeChargeId = $message->getstripeChargeId();

        $stripeCharge = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        if (null === $stripeCharge) {
            return;
        }

        try {
            $this->stripeProxy->capture($stripeCharge->getStripeChargeId(), $message->getAmount());
            $stripeCharge->capture();
            $this->stripeChargeRepository->persistAndFlush($stripeCharge);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $stripeCharge->getStripeChargeId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
