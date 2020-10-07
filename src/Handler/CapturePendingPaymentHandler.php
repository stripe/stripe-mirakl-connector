<?php

namespace App\Handler;

use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripePaymentRepository;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CapturePendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
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

    public function __invoke(CapturePendingPaymentMessage $message)
    {
        $stripePayment = $message->getStripePayment();

        try {
            $this->stripeProxy->capture($stripePayment->getStripePaymentId(), $message->getAmount());
            $stripePayment->capture();
            $this->stripePaymentRepository->persistAndFlush($stripePayment);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Payment: %s.', $e->getMessage()), [
                'paymentId' => $stripePayment->getStripePaymentId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
