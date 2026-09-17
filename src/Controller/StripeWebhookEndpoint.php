<?php

namespace App\Controller;

use App\Entity\PaymentMapping;
use App\Entity\StripePayout;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Event;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class StripeWebhookEndpoint extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const HANDLED_EVENT_TYPES = [
        'account.updated',
        'charge.succeeded',
        'charge.updated',
        'payout.failed',
        'charge.captured'
    ];

    public const DEPRECATED_EVENT_TYPES = [
        'payment_intent.created',
        'payment_intent.succeeded',
        'payment_intent.amount_capturable_updated',
    ];

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var string
     */
    private $webhookSellerSecret;

    /**
     * @var string
     */
    private $webhookOperatorSecret;

    /**
     * @var string
     */
    private $metadataCommercialOrderId;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    public function __construct(
        MessageBusInterface $bus,
        StripeClient $stripeClient,
        AccountMappingRepository $accountMappingRepository,
        PaymentMappingRepository $paymentMappingRepository,
        StripePayoutRepository $stripePayoutRepository,
        MiraklClient $miraklClient,
        string $webhookSellerSecret,
        string $webhookOperatorSecret,
        string $metadataCommercialOrderId
    ) {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->webhookSellerSecret = $webhookSellerSecret;
        $this->webhookOperatorSecret = $webhookOperatorSecret;
        $this->metadataCommercialOrderId = $metadataCommercialOrderId;
    }

    #[Route('/api/public/webhook', methods: ['POST'], name: 'handle_stripe_webhook')]
    #[OA\Post(
        summary: 'Handle Stripe webhook (deprecated)',
        description: 'Should only be called by Stripe Webhooks.',
        deprecated: true,
        responses: [
            new OA\Response(response: 200, description: 'Webhook ok'),
            new OA\Response(response: 400, description: 'Bad request'),
        ],
        tags: ['Webhook']
    )]
    public function handleStripeWebhookDeprecated(Request $request): Response
    {
        return $this->handleStripeSellerWebhook($request);
    }

    #[Route('/api/public/webhook/sellers', methods: ['POST'], name: 'handle_stripe_seller_webhook')]
    #[OA\Post(
        summary: 'Handle Stripe webhook for sellers',
        description: 'Should only be called by Stripe Webhooks (with seller secret).',
        responses: [
            new OA\Response(response: 200, description: 'Webhook ok'),
            new OA\Response(response: 400, description: 'Bad request'),
        ],
        tags: ['Sellers Webhook']
    )]
    public function handleStripeSellerWebhook(Request $request): Response
    {
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent();
        if ($payload) {
            $payload = $request->getContent();
        } else {
            $payload = '';
        }

        return $this->handleStripeWebhook($payload, $signatureHeader, $this->webhookSellerSecret);
    }

    #[Route('/api/public/webhook/operator', methods: ['POST'], name: 'handle_stripe_operator_webhook')]
    #[OA\Post(
        summary: 'Handle Stripe webhook for operator',
        description: 'Should only be called by Stripe Webhooks (with operator secret).',
        responses: [
            new OA\Response(response: 200, description: 'Webhook ok'),
            new OA\Response(response: 400, description: 'Bad request'),
        ],
        tags: ['Operator Webhook']
    )]
    public function handleStripeOperatorWebhook(Request $request): Response
    {
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent();
        if ($payload) {
            $payload = $request->getContent();
        } else {
            $payload = '';
        }

        return $this->handleStripeWebhook($payload, $signatureHeader, $this->webhookOperatorSecret);
    }

    protected function handleStripeWebhook(string $payload, string $signatureHeader, string $webhookSecret): Response
    {
        try {
            $eventType = '';
            $event = $this->stripeClient->webhookConstructEvent($payload, $signatureHeader, $webhookSecret);
            if (isset($event['type'])) {
                $eventType = $event['type'];
                if (!is_array($eventType)) {
                    $eventType = (array) $eventType;
                }
                $eventType = implode('', $eventType);
            }
        } catch (\UnexpectedValueException $e) {
            $this->logger->error('Invalid payload.');

            return new Response('Invalid payload.', Response::HTTP_BAD_REQUEST);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->error('Invalid signature.');

            return new Response('Invalid signature.', Response::HTTP_BAD_REQUEST);
        }

        if (in_array($event['type'], self::DEPRECATED_EVENT_TYPES)) {
            return new Response(sprintf(
                'The event type %s is no longer required and can be removed in the webhook settings.',
                $eventType
            ), Response::HTTP_OK);
        }

        if (!in_array($event['type'], self::HANDLED_EVENT_TYPES)) {
            $this->logger->error(sprintf('Unhandled event type %s.', (string) $event['type']));

            return new Response('Unhandled event type', Response::HTTP_BAD_REQUEST);
        }

        $message = '';
        $status = Response::HTTP_OK;
        try {
            switch ($event->type) {
                case 'account.updated':
                    $message = $this->handleAccountEvent($event);
                    break;
                case 'charge.succeeded':
                case 'charge.updated':
                case 'charge.captured':
                    $message = $this->handleChargeEvent($event);
                    break;
                case 'payout.failed':
                    $message = $this->handlePayoutFailedEvent($event);
                    break;
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = $e->getCode();
        }

        return new Response($message, $status);
    }

    /**
     * @throws \Exception
     */
    private function handleAccountEvent(Event $event): string
    {
        $stripeAccount = $event->data->object;

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($stripeAccount['id']);
        if (null === $accountMapping) {
            $this->logger->info(sprintf('Ignoring account.updated event for non-Mirakl Stripe account: %s', $stripeAccount['id']));

            return 'Ignoring account.updated event for non-Mirakl Stripe account.';
        }

        if (!$stripeAccount['details_submitted']) {
            $this->logger->info(sprintf('Ignoring account.updated event until details are submitted for account: %s', $stripeAccount['id']));

            return 'Ignoring account.updated event until details are submitted for account.';
        }

        $accountMapping->setOnboardingToken(null);
        $accountMapping->setPayoutEnabled($stripeAccount['payouts_enabled']);
        $accountMapping->setPayinEnabled($stripeAccount['charges_enabled']);
        $accountMapping->setDisabledReason($stripeAccount['requirements']['disabled_reason']);

        $this->accountMappingRepository->flush();

        $this->bus->dispatch(new AccountUpdateMessage($accountMapping->getMiraklShopId(), $stripeAccount['id']));

        return 'Account mapping updated.';
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function handleChargeEvent(Event $event): string
    {
        $charge = $event->data->object;
        assert($charge instanceof \Stripe\Charge);

        $stripeAccount = $event->account ?? null;

        $miraklCommercialOrderId = $this->findMiraklCommercialOrderId($charge);
        if (!$miraklCommercialOrderId) {
            $this->logger->info(sprintf('Ignoring event with no Mirakl Commercial Order ID for Stripe charge: %s', $charge->id));
            return 'Ignoring event with no Mirakl Commercial Order ID.';
        }

        if (!empty($stripeAccount)) {
            // Fetch both product and service orders unconditionally so that hybrid
            // commercial orders (containing sub-orders of both types) include every
            // seller's shop in the authorization check. The previous if-empty fallback
            // silently dropped service shop IDs whenever product orders also existed.
            $productOrderList = $this->miraklClient->listProductOrdersByCommercialId([$miraklCommercialOrderId]);
            $serviceOrderList = $this->miraklClient->listServiceOrdersByCommercialId([$miraklCommercialOrderId]);

            if (empty($productOrderList) && empty($serviceOrderList)) {
                $this->logger->info(sprintf('Ignoring event with no Mirakl Order for Stripe charge: %s', $charge->id));
                return 'Ignoring event with no Mirakl Order.';
            }

            // Each list is keyed [commercialId => [orderId => Order]]; iterate the inner
            // maps to collect all unique shop IDs from product and service orders alike.
            $shopIds = [];
            foreach ($productOrderList as $orders) {
                foreach ($orders as $order) {
                    $shopId = $order->getShopId();
                    if ($shopId !== null) {
                        $shopIds[$shopId] = $shopId;
                    }
                }
            }
            foreach ($serviceOrderList as $orders) {
                foreach ($orders as $order) {
                    $shopId = $order->getShopId();
                    if ($shopId !== null) {
                        $shopIds[$shopId] = $shopId;
                    }
                }
            }
            $shopIds = array_values($shopIds);

            // findByMiraklShopIds returns an array keyed by miraklShopId (int).
            $accountMappings = $this->accountMappingRepository->findByMiraklShopIds($shopIds);

            // Every shop in the commercial order must have an account mapping.
            if (count($accountMappings) !== count($shopIds)) {
                $this->logger->info(sprintf('Ignoring event for unknown Mirakl shop for Stripe charge: %s', $charge->id));
                return 'Ignoring event for unknown Mirakl shop.';
            }

            // All shops must map to the SAME Stripe account, and that account must be the
            // event sender. For the aggregate-payment model a connected-account event is only
            // valid for a commercial order that belongs entirely to one seller. If the order
            // spans sellers with different accounts, no connected-account event can claim it.
            $mappedAccountIds = [];
            foreach ($shopIds as $shopId) {
                $mappedAccountIds[$accountMappings[$shopId]->getStripeAccountId()] = true;
            }
            if (count($mappedAccountIds) !== 1 || !isset($mappedAccountIds[$stripeAccount])) {
                $this->logger->info(sprintf('Ignoring event for unknown Stripe account for Stripe charge: %s', $charge->id));
                return 'Ignoring event for unknown Stripe account.';
            }
        }

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($charge->id);

        if ('failed' === $charge->status) {
            $status = $charge->status;
        } else {
            $status = isset($charge->captured) && $charge->captured ? PaymentMapping::CAPTURED : PaymentMapping::TO_CAPTURE;
        }

        if (!$paymentMapping) {
            $paymentMapping = new PaymentMapping();
            $paymentMapping->setStripeChargeId($charge->id);
            $paymentMapping->setStripeAmount($charge->amount);
            $paymentMapping->setStatus($status);
            $paymentMapping->setMiraklCommercialOrderId($miraklCommercialOrderId);

            $existingForOrder = $this->paymentMappingRepository->persistIfCommercialOrderIsUnmapped($paymentMapping);
            if (null !== $existingForOrder) {
                $this->logger->info(sprintf(
                    'Ignoring event: payment mapping already exists for commercial order %s (existing charge: %s, event charge: %s)',
                    $miraklCommercialOrderId,
                    $existingForOrder->getStripeChargeId(),
                    $charge->id
                ));
                return 'Ignoring event: payment mapping already exists for this commercial order.';
            }

            $message = 'Payment mapping created.';
        } else {
            // Reject if the event metadata tries to move this charge to a different commercial
            // order than the one already recorded. An attacker who controls a known charge can
            // otherwise shift a legitimate mapping to a victim order via a charge.updated event.
            $existingCommercialOrderId = $paymentMapping->getMiraklCommercialOrderId();
            if ($existingCommercialOrderId !== null && $existingCommercialOrderId !== $miraklCommercialOrderId) {
                $this->logger->info(sprintf(
                    'Ignoring event: charge %s is already mapped to commercial order %s, refusing reassignment to %s',
                    $charge->id,
                    $existingCommercialOrderId,
                    $miraklCommercialOrderId
                ));
                return 'Ignoring event: charge is already mapped to a different commercial order.';
            }
            $paymentMapping->setStatus($status);
            $paymentMapping->setMiraklCommercialOrderId($miraklCommercialOrderId);
            if ($status === PaymentMapping::CAPTURED) {
                $paymentMapping->setStatusReason(null);
            }
            $this->paymentMappingRepository->flush();
            $message = 'Payment mapping updated.';
        }

        return $message;
    }

    private function handlePayoutFailedEvent(Event $event): string
    {
        $payout = $event->data->object;
        $stripePayoutId = $payout['id'];

        $stripePayout = $this->stripePayoutRepository->findOneByPayoutId($stripePayoutId);
        if (null === $stripePayout) {
            $this->logger->info(sprintf('Ignoring payout.failed event for unknown payout: %s', $stripePayoutId));

            return 'Ignoring payout.failed event for unknown payout.';
        }

        $failureCode = $payout['failure_code'] ?? 'unknown_code';
        $failureMessage = $payout['failure_message'] ?? 'Unknown failure reason';
        $statusReason = $failureCode . ': ' . $failureMessage;
        $stripePayout->setStatus(StripePayout::PAYOUT_FAILED);
        $stripePayout->setStatusReason($statusReason);

        $this->stripePayoutRepository->flush();

        $this->logger->info(sprintf('Payout %s marked as failed: %s', $stripePayoutId, $statusReason));

        return 'Payout status updated to failed.';
    }

    /**
     * @throws \Exception
     */
    private function findMiraklCommercialOrderId(\Stripe\Charge $charge): ?string
    {
        if (isset($charge->metadata[$this->metadataCommercialOrderId])) {
            if ('' === $charge->metadata[$this->metadataCommercialOrderId]) {
                $message = sprintf('%s is empty in Charge metadata.', $this->metadataCommercialOrderId);
                $this->logger->error($message);
                throw new \Exception($message, Response::HTTP_BAD_REQUEST);
            }
            $metadataCommercialOrderId = $charge->metadata[$this->metadataCommercialOrderId];

            if (!is_array($metadataCommercialOrderId)) {
                $metadataCommercialOrderId = (array) $metadataCommercialOrderId;
            }

            return implode('', $metadataCommercialOrderId);
        }

        // Fallback to linked payment intent to see if it contains the metadata
        if (isset($charge->payment_intent)) {
            $paymentIntent = $charge->payment_intent;
            if (is_string($paymentIntent)) {
                $paymentIntent = $this->stripeClient->paymentIntentRetrieve($paymentIntent);
            }
            if (isset($paymentIntent->metadata[$this->metadataCommercialOrderId])) {
                if ('' === $paymentIntent->metadata[$this->metadataCommercialOrderId]) {
                    $message = sprintf('%s is empty in PaymentIntent.', $this->metadataCommercialOrderId);
                    $this->logger->error($message);
                    throw new \Exception($message, Response::HTTP_BAD_REQUEST);
                }
                $metadataCommercialOrderId = $paymentIntent->metadata[$this->metadataCommercialOrderId];

                if (!is_array($metadataCommercialOrderId)) {
                    $metadataCommercialOrderId = (array) $metadataCommercialOrderId;
                }

                return implode('', $metadataCommercialOrderId);
            }
        }

        return null;
    }
}
