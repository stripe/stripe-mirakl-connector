<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CapturePendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
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
        assert(null !== $paymentMapping);
        assert(PaymentMapping::TO_CAPTURE === $paymentMapping->getStatus());

        try {
            $this->stripeClient->capturePayment(
                $paymentMapping->getStripeChargeId(),
                $message->getAmount()
            );

            $paymentMapping->capture();
            $this->paymentMappingRepository->flush();
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'mirakl_commercial_id' => $paymentMapping->getMiraklCommercialOrderId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
