<?php

namespace App\Handler;

use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripeChargeRepository;
use App\Service\StripeClient;
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
     * @var StripeClient
     */
    private $stripeClient;

    public function __construct(
        StripeClient $stripeClient,
        StripeChargeRepository $stripeChargeRepository
    ) {
        $this->stripeClient = $stripeClient;
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
            $this->stripeClient->cancelBeforeCapture($stripeCharge->getStripeChargeId(), $message->getAmount());
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
