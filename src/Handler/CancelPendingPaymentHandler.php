<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\CancelPendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class CancelPendingPaymentHandler implements MessageHandlerInterface, LoggerAwareInterface
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
        assert(null !== $paymentMapping);
        assert(PaymentMapping::TO_CAPTURE === $paymentMapping->getStatus());

        try {
            $this->stripeClient->cancelPayment($paymentMapping->getStripeChargeId());
            $paymentMapping->cancel();
            $this->paymentMappingRepository->flush();
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not cancel Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'mirakl_commercial_id' => $paymentMapping->getMiraklCommercialOrderId(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
        }
    }
}
