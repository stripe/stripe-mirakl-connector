<?php

namespace App\Handler;

use App\Entity\StripePayout;
use App\Message\ProcessPayoutMessage;
use App\Repository\StripePayoutRepository;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ProcessPayoutHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    public function __construct(
        StripeClient $stripeClient,
        StripePayoutRepository $stripePayoutRepository
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripePayoutRepository = $stripePayoutRepository;
    }

    public function __invoke(ProcessPayoutMessage $message): void
    {
        $payout = $this->stripePayoutRepository->findOneBy([
            'id' => $message->getStripePayoutId(),
        ]);
        assert(null !== $payout && null !== $payout->getAccountMapping());
        assert(StripePayout::PAYOUT_CREATED !== $payout->getStatus());

        $accountMapping = $payout->getAccountMapping();
        try {
            $response = $this->stripeClient->createPayout(
                (string) $payout->getCurrency(),
                (int) $payout->getAmount(),
                $accountMapping->getStripeAccountId(),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'invoiceId' => $payout->getMiraklInvoiceId(),
                ]
            );

            $payout->setPayoutId($response->id);
            $payout->setStatus(StripePayout::PAYOUT_CREATED);
            $payout->setStatusReason(null);
        } catch (ApiErrorException $e) {
            $this->logger->error(
                sprintf('Could not create Stripe Payout: %s.', $e->getMessage()),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'stripeAccountId' => $accountMapping->getStripeAccountId(),
                    'stripePayoutId' => $payout->getMiraklInvoiceId(),
                    'stripeErrorCode' => $e->getStripeCode(),
                ]
            );

            $payout->setStatus(StripePayout::PAYOUT_FAILED);
            $payout->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->stripePayoutRepository->flush();
    }
}
