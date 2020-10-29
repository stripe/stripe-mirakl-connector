<?php

namespace App\Handler;

use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripePaymentRepository;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CancelPendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripePaymentRepository
     */
    private $stripePaymentRepository;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(
        StripeProxy $stripeProxy,
        StripePaymentRepository $stripePaymentRepository
    ) {
        $this->stripeProxy = $stripeProxy;
        $this->stripePaymentRepository = $stripePaymentRepository;
    }

    public function __invoke(CancelPendingPaymentMessage $message)
    {
        $stripePaymentId = $message->getStripePaymentId();

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        if (null === $stripePayment) {
            return;
        }

        try {
            $this->stripeProxy->cancelBeforeCapture($stripePayment->getStripePaymentId(), $message->getAmount());
            $stripePayment->cancel();
            $this->stripePaymentRepository->persistAndFlush($stripePayment);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not cancel Stripe Payment: %s.', $e->getMessage()), [
                'paymentId' => $stripePayment->getStripePaymentId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
