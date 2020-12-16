<?php

namespace App\Handler;

use App\Entity\StripePayout;
use App\Message\ProcessPayoutMessage;
use App\Repository\StripePayoutRepository;
use App\Service\MiraklClient;
use App\Service\StripeClient;
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
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        StripePayoutRepository $stripePayoutRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->stripePayoutRepository = $stripePayoutRepository;
    }

    public function __invoke(ProcessPayoutMessage $message)
    {
        $stripePayoutId = $message->getStripePayoutId();

        $stripePayout = $this->stripePayoutRepository->findOneBy([
            'id' => $stripePayoutId,
        ]);

        if (null === $stripePayout) {
            return;
        }

        $accountMapping = $stripePayout->getAccountMapping();
        if (
            null === $accountMapping ||
            null === $accountMapping->getStripeAccountId() ||
            !$accountMapping->getPayoutEnabled()
        ) {
            $stripePayout
                ->setFailedReason('Unknown account, or payout is not enabled on this Stripe account')
                ->setStatus(StripePayout::PAYOUT_FAILED);
            $this->stripePayoutRepository->persistAndFlush($stripePayout);

            return;
        }

        $amount = $stripePayout->getAmount();
        $currency = $stripePayout->getCurrency();
        $stripeAccountId = $accountMapping->getStripeAccountId();
        $invoiceId = $stripePayout->getMiraklInvoiceId();

        try {
            $response = $this->stripeClient->createPayout($currency, $amount, $stripeAccountId, [
                'miraklShopId' => $accountMapping->getMiraklShopId(),
                'invoiceId' => $invoiceId,
            ]);
            $payoutId = $response->id;
            $stripePayout
                ->setStatus(StripePayout::PAYOUT_CREATED)
                ->setStripePayoutId($payoutId);
        } catch (ApiErrorException $e) {
            $this->logger->error(sprintf('Could not create Stripe Payout: %s.', $e->getMessage()), [
                'miraklShopId' => $accountMapping->getMiraklShopId(),
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
