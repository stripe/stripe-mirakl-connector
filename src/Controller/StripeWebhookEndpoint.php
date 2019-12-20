<?php

namespace App\Controller;

use App\Message\AccountUpdateMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Utils\StripeProxy;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class StripeWebhookEndpoint extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const HANDLED_EVENT_TYPES = [
        'account.updated',
    ];

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var MiraklStripeMappingRepository
     */
    private $miraklStripeMappingRepository;

    public function __construct(
        MessageBusInterface $bus,
        StripeProxy $stripeProxy,
        MiraklStripeMappingRepository $miraklStripeMappingRepository
    ) {
        $this->bus = $bus;
        $this->stripeProxy = $stripeProxy;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
    }

    private function handles(string $eventType)
    {
        return in_array($eventType, self::HANDLED_EVENT_TYPES);
    }

    /**
     * Update an account status.
     * Should only be called by Stripe Webhhoks.
     *
     * @SWG\Response(
     *     response=200,
     *     description="Account updated",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Bad request",
     * )
     *
     * @SWG\Tag(name="Webhook")
     * @Route("/api/public/webhook", methods={"POST"}, name="handle_stripe_webhook")
     */
    public function handleStripeWebhook(Request $request): Response
    {
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent() ?? '';

        try {
            $event = $this->stripeProxy->webhookConstructEvent($payload, $signatureHeader);
        } catch (\UnexpectedValueException $e) {
            $this->logger->error('Invalid payload');

            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->error('Invalid signature');

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        if (!$this->handles($event['type'])) {
            $this->logger->error(sprintf('Unhandled event type %s', $event['type']));

            return new Response('Unhandled event type', Response::HTTP_BAD_REQUEST);
        }

        $stripeAccount = $event->data->object;

        $miraklStripeMapping = $this->miraklStripeMappingRepository->findOneByStripeAccountId($stripeAccount['id']);
        if (!$miraklStripeMapping) {
            $this->logger->error(sprintf('This Stripe Account does not exist %s', $stripeAccount['id']));

            return new Response('This Stripe Account does not exist', Response::HTTP_BAD_REQUEST);
        }

        $miraklStripeMapping
            ->setPayoutEnabled($stripeAccount['payouts_enabled'])
            ->setPayinEnabled($stripeAccount['charges_enabled'])
            ->setDisabledReason($stripeAccount['disabled_reason']);

        $this->miraklStripeMappingRepository->persistAndFlush($miraklStripeMapping);

        $this->bus->dispatch(new AccountUpdateMessage($miraklStripeMapping->getMiraklShopId(), $stripeAccount['id']));

        return new Response('Account updated', Response::HTTP_OK);
    }
}
