<?php

namespace App\Handler;

use App\Entity\StripePayout;
use App\Message\ProcessPayoutMessage;
use App\Repository\StripePayoutRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessPayoutHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripeProxy $stripeProxy,
        StripePayoutRepository $stripePayoutRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripePayoutRepository = $stripePayoutRepository;
    }

    public function __invoke(ProcessPayoutMessage $message)
    {
        $stripePayoutId = $message->getStripePayoutId();

        $stripePayout = $this->stripePayoutRepository->findOneBy([
            'id' => $stripePayoutId,
        ]);
        $miraklStripeMapping = $stripePayout->getMiraklStripeMapping();
        if (!$miraklStripeMapping->getPayoutEnabled()) {
            $stripePayout
                ->setFailedReason('Payout is not enabled on this Stripe account')
                ->setStatus(StripePayout::PAYOUT_FAILED);
            $this->stripePayoutRepository->persistAndFlush($stripePayout);

            return;
        }
        $amount = $stripePayout->getAmount();
        $currency = $stripePayout->getCurrency();
        $stripeAccountId = $miraklStripeMapping->getStripeAccountId();
        $invoiceId = $stripePayout->getMiraklInvoiceId();

        try {
            $response = $this->stripeProxy->createPayout($currency, $amount, $stripeAccountId, [
                'miraklShopId' => $miraklStripeMapping->getMiraklShopId(),
                'invoiceId' => $invoiceId,
            ]);
            $payoutId = $response->id;
            $stripePayout
                ->setStatus(StripePayout::PAYOUT_CREATED)
                ->setStripePayoutId($payoutId);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not create Stripe Payout: %s.', $e->getMessage()), [
                'miraklShopId' => $miraklStripeMapping->getMiraklShopId(),
                'stripePayoutId' => $invoiceId,
                'stripeErrorCode' => $e->getStripeCode(),
            ]);
            $stripePayout
                ->setStatus(StripePayout::PAYOUT_FAILED)
                ->setFailedReason(substr($e->getMessage(), 0, 1024));
        }
        $this->stripePayoutRepository->persistAndFlush($stripePayout);
    }
}
