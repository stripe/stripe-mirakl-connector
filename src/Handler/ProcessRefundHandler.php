<?php

namespace App\Handler;

use App\Entity\StripeRefund;
use App\Message\ProcessRefundMessage;
use App\Repository\StripeRefundRepository;
use App\Service\ErrorContext;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use App\Service\StripeAmount;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class ProcessRefundHandler implements LoggerAwareInterface
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
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    public function __construct(
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        StripeRefundRepository $stripeRefundRepository
    ) {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->stripeRefundRepository = $stripeRefundRepository;
    }

    public function __invoke(ProcessRefundMessage $message): void
    {
        $refund = $this->stripeRefundRepository->findOneBy([
            'id' => $message->getStripeRefundId(),
        ]);
        assert(null !== $refund && null !== $refund->getTransactionId());
        assert(StripeRefund::REFUND_CREATED !== $refund->getStatus());

        try {
            if (!$refund->getStripeRefundId()) {
                $response = $this->stripeClient->createRefund(
                    $refund->getTransactionId(),
                    $refund->getAmount(),
                    ['miraklRefundId' => $refund->getMiraklRefundId()]
                );

                $refund->setStripeRefundId($response->id);
            }

            if (StripeRefund::REFUND_SERVICE_ORDER === $refund->getType()) {
                $this->miraklClient->validateServicePendingRefunds([[
                    'id' => $refund->getMiraklRefundId(),
                    'order_id' => $refund->getMiraklOrderId(),
                    'amount' => StripeAmount::fromMinorUnits($refund->getMiraklAmount(), $refund->getCurrency()),
                    'currency_code' => strtoupper($refund->getCurrency()),
                    'state' => 'OK',
                    'transaction_number' => $refund->getStripeRefundId(),
                ]]);
            } else {
                $this->miraklClient->validateProductPendingRefunds([[
                    'refund_id' => $refund->getMiraklRefundId(),
                    'amount' => StripeAmount::fromMinorUnits($refund->getMiraklAmount(), $refund->getCurrency()),
                    'currency_iso_code' => strtoupper($refund->getCurrency()),
                    'payment_status' => 'OK',
                    'transaction_number' => $refund->getStripeRefundId(),
                ]]);
            }

            $refund->setMiraklValidationTime(new \DateTime());
            $refund->setStatus(StripeRefund::REFUND_CREATED);
            $refund->setStatusReason(null);
        } catch (ApiErrorException $e) {
            $this->logger->error(
                'Could not create refund in Stripe.',
                [
                    'miraklRefundId' => $refund->getMiraklRefundId(),
                    'miraklOrderId' => $refund->getMiraklOrderId(),
                ] + ErrorContext::fromThrowable($e)
            );

            $refund->setStatus(StripeRefund::REFUND_FAILED);
            $refund->setStatusReason(substr($e->getMessage(), 0, 1024));
        } catch (TransportException $e) {
            $this->logger->error(
                'Timeout processing refund.',
                [
                    'stripeRefundId' => $refund->getStripeRefundId(),
                    'miraklRefundId' => $refund->getMiraklRefundId(),
                    'miraklOrderId' => $refund->getMiraklOrderId(),
                ] + ErrorContext::fromThrowable($e)
            );

            $refund->setStatus(StripeRefund::REFUND_FAILED);
            $refund->setStatusReason(substr($e->getMessage(), 0, 1024));
        } catch (ClientException $e) {
            $message = $e->getResponse()->getContent(false);
            $this->logger->error(
                'Could not validate refund in Mirakl.',
                [
                    'miraklRefundId' => $refund->getMiraklRefundId(),
                    'miraklOrderId' => $refund->getMiraklOrderId(),
                ] + ErrorContext::fromThrowable($e)
            );

            $refund->setStatus(StripeRefund::REFUND_FAILED);
            $refund->setStatusReason(substr($message, 0, 1024));
        }

        $this->stripeRefundRepository->flush();
    }
}
