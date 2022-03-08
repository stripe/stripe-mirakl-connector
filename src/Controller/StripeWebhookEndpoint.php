<?php

namespace App\Controller;

use App\Entity\PaymentMapping;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\ORMException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Event;
use Swagger\Annotations as SWG;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;

class StripeWebhookEndpoint extends AbstractController implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const HANDLED_EVENT_TYPES = [
        'account.updated',
        'charge.succeeded',
        'charge.updated',
    ];

    public const DEPRECATED_EVENT_TYPES = [
        'payment_intent.created',
        'payment_intent.succeeded',
        'payment_intent.amount_capturable_updated'
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

    public function __construct(
        MessageBusInterface $bus,
        StripeClient $stripeClient,
        AccountMappingRepository $accountMappingRepository,
        PaymentMappingRepository $paymentMappingRepository,
        string $webhookSellerSecret,
        string $webhookOperatorSecret,
        string $metadataCommercialOrderId
    ) {
        $this->bus = $bus;
        $this->stripeClient = $stripeClient;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->webhookSellerSecret = $webhookSellerSecret;
        $this->webhookOperatorSecret = $webhookOperatorSecret;
        $this->metadataCommercialOrderId = $metadataCommercialOrderId;
    }

    /**
     * Should only be called by Stripe Webhooks.
     *
     * @SWG\Response(
     *     response=200,
     *     description="Webhook ok",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Bad request",
     * )
     * @SWG\Post(deprecated=true)
     * @SWG\Tag(name="Webhook")
     * @Route("/api/public/webhook", methods={"POST"}, name="handle_stripe_webhook")
     * @param Request $request
     * @return Response
     */
    public function handleStripeWebhookDeprecated(Request $request): Response
    {
        return $this->handleStripeSellerWebhook($request);
    }

    /**
     * Should only be called by Stripe Webhooks (with seller secret).
     *
     * @SWG\Response(
     *     response=200,
     *     description="Webhook ok",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Bad request",
     * )
     *
     * @SWG\Tag(name="Sellers Webhook")
     * @Route("/api/public/webhook/sellers", methods={"POST"}, name="handle_stripe_seller_webhook")
     * @param Request $request
     * @return Response
     */
    public function handleStripeSellerWebhook(Request $request): Response
    {
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent() ?? '';

        return $this->handleStripeWebhook($payload, $signatureHeader, $this->webhookSellerSecret);
    }

    /**
     * Should only be called by Stripe Webhooks (with operator secret).
     *
     * @SWG\Response(
     *     response=200,
     *     description="Webhook ok",
     * )
     * @SWG\Response(
     *     response=400,
     *     description="Bad request",
     * )
     * @SWG\Tag(name="Operator Webhook")
     * @Route("/api/public/webhook/operator", methods={"POST"}, name="handle_stripe_operator_webhook")
     * @param Request $request
     * @return Response
     */
    public function handleStripeOperatorWebhook(Request $request): Response
    {
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent() ?? '';

        return $this->handleStripeWebhook($payload, $signatureHeader, $this->webhookOperatorSecret);
    }

    protected function handleStripeWebhook($payload, string $signatureHeader, string $webhookSecret): Response
    {
        try {
            $event = $this->stripeClient->webhookConstructEvent($payload, $signatureHeader, $webhookSecret);
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
                $event['type']
            ), Response::HTTP_OK);
        }

        if (!in_array($event['type'], self::HANDLED_EVENT_TYPES)) {
            $this->logger->error(sprintf('Unhandled event type %s.', $event['type']));
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
                    $message = $this->handleChargeEvent($event);
                    break;
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = $e->getCode();
        }

        return new Response($message, $status);
    }

    /**
     * @param Event $event
     * @return string
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
     * @param Event $event
     * @return string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function handleChargeEvent(Event $event): string
    {
        $charge = $event->data->object;
        assert($charge instanceof \Stripe\Charge);

        if ('failed' === $charge->status) {
            return 'Ignoring failed charge event.';
        }

        $miraklCommercialOrderId = $this->findMiraklCommercialOrderId($charge);
        if (!$miraklCommercialOrderId) {
            return 'Ignoring event with no Mirakl Commercial Order ID.';
        }

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($charge->id);
        $status = isset($charge->captured) && $charge->captured ? PaymentMapping::CAPTURED : PaymentMapping::TO_CAPTURE;
        if (!$paymentMapping) {
            $paymentMapping = new PaymentMapping();
            $paymentMapping->setStripeChargeId($charge->id);
            $paymentMapping->setStripeAmount($charge->amount);
            $paymentMapping->setStatus($status);
            $paymentMapping->setMiraklCommercialOrderId($miraklCommercialOrderId);
            $this->paymentMappingRepository->persist($paymentMapping);
            $message = 'Payment mapping created.';
        } else {
            $paymentMapping->setStatus($status);
            $paymentMapping->setMiraklCommercialOrderId($miraklCommercialOrderId);
            $message = 'Payment mapping updated.';
        }


        $this->paymentMappingRepository->flush();

        return $message;
    }

    /**
     * @throws \Exception
     */
    private function findMiraklCommercialOrderId(\Stripe\Charge $charge): ?string
    {
        if (isset($charge['metadata'][$this->metadataCommercialOrderId])) {
            if ('' === $charge['metadata'][$this->metadataCommercialOrderId]) {
                $message = sprintf('%s is empty in Charge metadata.', $this->metadataCommercialOrderId);
                $this->logger->error($message);
                throw new \Exception($message, Response::HTTP_BAD_REQUEST);
            }

            return $charge['metadata'][$this->metadataCommercialOrderId];
        }

        // Fallback to linked payment intent to see if it contains the metadata
        if (isset($charge->payment_intent)) {
            $paymentIntent = $charge->payment_intent;
            if (is_string($paymentIntent)) {
                $paymentIntent = $this->stripeClient->paymentIntentRetrieve($paymentIntent);
            }

            if (isset($paymentIntent['metadata'][$this->metadataCommercialOrderId])) {
                if ('' === $paymentIntent['metadata'][$this->metadataCommercialOrderId]) {
                    $message = sprintf('%s is empty in PaymentIntent.', $this->metadataCommercialOrderId);
                    $this->logger->error($message);
                    throw new \Exception($message, Response::HTTP_BAD_REQUEST);
                }

                return $paymentIntent['metadata'][$this->metadataCommercialOrderId];
            }
        }

        return null;
    }
}
