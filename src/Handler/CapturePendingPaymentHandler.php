<?php

namespace App\Handler;

use App\Entity\PaymentMapping;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class CapturePendingPaymentHandler implements LoggerAwareInterface
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
        assert(in_array($paymentMapping->getStatus(), [PaymentMapping::TO_CAPTURE, PaymentMapping::CAPTURE_FAILED, PaymentMapping::CANCEL_FAILED], true));

        try {
            $charge = $this->stripeClient->chargeRetrieve($paymentMapping->getStripeChargeId());
            if ($charge->captured) {
                $paymentMapping->capture();
            } else {
                $this->stripeClient->capturePayment(
                    $paymentMapping->getStripeChargeId(),
                    $message->getAmount()
                );

                $paymentMapping->capture();
            }
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not capture Stripe Charge: %s.', $e->getMessage()), [
                'chargeId' => $paymentMapping->getStripeChargeId(),
                'amount' => $message->getAmount(),
                'stripeErrorCode' => $e->getStripeCode(),
            ]);

            $paymentMapping->setStatus(PaymentMapping::CAPTURE_FAILED);
            $paymentMapping->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->paymentMappingRepository->flush();
    }
}
