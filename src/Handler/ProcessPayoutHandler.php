<?php

namespace App\Handler;

use App\Entity\StripePayout;
use App\Helper\LoggerHelper;
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
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;
    /**
     * @var LoggerHelper
     */
    private $loggerHelper;

    public function __construct(
        StripeClient $stripeClient,
        StripePayoutRepository $stripePayoutRepository,
        LoggerHelper $loggerHelper
    ) {
        $this->stripeClient = $stripeClient;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->loggerHelper = $loggerHelper;
    }

    public function __invoke(ProcessPayoutMessage $message)
    {
        $payout = $this->stripePayoutRepository->findOneBy([
            'id' => $message->getStripePayoutId(),
        ]);
        assert(null !== $payout && null !== $payout->getAccountMapping());
        assert(StripePayout::PAYOUT_CREATED !== $payout->getStatus());

        $accountMapping = $payout->getAccountMapping();
        try {
            $response = $this->stripeClient->createPayout(
                $payout->getCurrency(),
                $payout->getAmount(),
                $accountMapping->getStripeAccountId(),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'invoiceId' => $payout->getMiraklInvoiceId(),
                ]
            );

            $payout->setPayoutId($response->id);
            $payout->setStatus(StripePayout::PAYOUT_CREATED);
            $payout->setStatusReason(null);

            $this->loggerHelper->getLogger()->info('Payout processed', [
                'miraklShopId' => $accountMapping->getMiraklShopId(),
                'invoiceId' => $payout->getMiraklInvoiceId(),
            ]);
        } catch (ApiErrorException $e) {
            $this->logger->error(
                sprintf('Could not create Stripe Payout: %s.', $e->getMessage()),
                [
                    'miraklShopId' => $accountMapping->getMiraklShopId(),
                    'stripePayoutId' => $payout->getMiraklInvoiceId(),
                    'stripeErrorCode' => $e->getStripeCode(),
                ]
            );

            $this->loggerHelper->getLogger()->error('Could not create Stripe Payout', [
                'miraklShopId' => $accountMapping->getMiraklShopId(),
                'stripePayoutId' => $payout->getMiraklInvoiceId(),
                'extra' => [
                    'stripeErrorCode' => $e->getStripeCode(),
                    'error' => $e->getMessage()
                ]
            ]);

            $payout->setStatus(StripePayout::PAYOUT_FAILED);
            $payout->setStatusReason(substr($e->getMessage(), 0, 1024));
        }

        $this->stripePayoutRepository->flush();
    }
}
