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
        assert(null !== $paymentMapping);
        assert(in_array($paymentMapping->getStatus(), [PaymentMapping::TO_CAPTURE, PaymentMapping::CAPTURE_FAILED, PaymentMapping::CANCEL_FAILED], true));

        try {
            $this->stripeClient->cancelPayment($paymentMapping->getStripeChargeId());
            $paymentMapping->cancel();
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not cancel Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $paymentMapping->setStatus(PaymentMapping::CANCEL_FAILED);
            $paymentMapping->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->paymentMappingRepository->flush();
    }
}
