<?php

namespace App\Controller;

use App\Entity\StripeCharge;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeChargeRepository;
use App\Utils\StripeProxy;
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


    public const STRIPE_PAYMENT_LISTEN_STATUS = [
        'succeeded',
        'pending'
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
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripeChargeRepository
     */
    private $stripeChargeRepository;

    /**
     * @var string
     */
    private $metadataOrderIdFieldName;

    public function __construct(
        MessageBusInterface $bus,
        StripeProxy $stripeProxy,
        AccountMappingRepository $accountMappingRepository,
        StripeChargeRepository $stripeChargeRepository,
        string $metadataOrderIdFieldName
    ) {
        $this->bus = $bus;
        $this->stripeProxy = $stripeProxy;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->stripeChargeRepository = $stripeChargeRepository;
        $this->metadataOrderIdFieldName = $metadataOrderIdFieldName;
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

        return $this->handleStripeWebhook($payload, $signatureHeader, false);
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

        return $this->handleStripeWebhook($payload, $signatureHeader, true);
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
        $signatureHeader = $request->headers->get('stripe-signature') ?? '';
        $payload = $request->getContent() ?? '';

        return $this->handleStripeWebhook($payload, $signatureHeader, false);
    }

    protected function handleStripeWebhook($payload, string $signatureHeader, bool $isOperatorWebhook): Response
    {
        try {
            $event = $this->stripeProxy->webhookConstructEvent($payload, $signatureHeader, $isOperatorWebhook);
        } catch (\UnexpectedValueException $e) {
            $this->logger->error('Invalid payload');

            return new Response('Invalid payload', Response::HTTP_BAD_REQUEST);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $this->logger->error('Invalid signature');

            return new Response('Invalid signature', Response::HTTP_BAD_REQUEST);
        }

        if ($this->ignores($event['type'])) {
            return new Response(sprintf(
                'The event type %s is no longer required and can be removed in the operator webhook settings.',
                $event['type']
            ), Response::HTTP_OK);
        }

        if (!$this->handles($event['type'])) {
            $this->logger->error(sprintf('Unhandled event type %s', $event['type']));

            return new Response('Unhandled event type', Response::HTTP_BAD_REQUEST);
        }

        $status = Response::HTTP_OK;
        try {
            switch ($event->type) {
                case 'account.updated':
                    $message = $this->onAccountUpdated($event);
                    break;
                case 'charge.succeeded':
                case 'charge.updated':
                    $message = $this->onChargeCreated($event);
                    break;
                default:
                    // should never be triggered
                    $message = 'Not managed yet';
                    $status = Response::HTTP_BAD_REQUEST;
                    break;
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = $e->getCode();

            if (!isset(Response::$statusTexts[$status])) {
                $status = Response::HTTP_INTERNAL_SERVER_ERROR;
            }
        }

        return new Response($message, $status);
    }

    /**
     * @param string $eventType
     * @return bool
     */
    private function handles(string $eventType)
    {
        return in_array($eventType, self::HANDLED_EVENT_TYPES);
    }

    /**
     * @param string $eventType
     * @return bool
     */
    private function ignores(string $eventType)
    {
        return in_array($eventType, self::DEPRECATED_EVENT_TYPES);
    }

    /**
     * @param Event $event
     * @return string
     * @throws \Exception
     */
    private function onAccountUpdated(Event $event): string
    {
        $stripeAccount = $event->data->object;

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($stripeAccount['id']);
        if (!$accountMapping) {
            $this->logger->error(sprintf('This Stripe Account does not exist %s', $stripeAccount['id']));

            throw new \Exception('This Stripe Account does not exist', Response::HTTP_BAD_REQUEST);
        }

        $accountMapping
            ->setPayoutEnabled($stripeAccount['payouts_enabled'])
            ->setPayinEnabled($stripeAccount['charges_enabled'])
            ->setDisabledReason($stripeAccount['disabled_reason']);

        $this->accountMappingRepository->persistAndFlush($accountMapping);

        $this->bus->dispatch(new AccountUpdateMessage($accountMapping->getMiraklShopId(), $stripeAccount['id']));

        return 'Account updated';
    }

    /**
     * @param Event $event
     * @return string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function onChargeCreated(Event $event): string
    {
        $stripeCharge = $event->data->object;
        if (!$stripeCharge instanceof \Stripe\Charge) {
            $message = sprintf('Webhook expected a charge. Received a %s instead.', \get_class($stripeCharge));
            $this->logger->error($message);

            throw new \Exception($message, Response::HTTP_BAD_REQUEST);
        }

        $miraklOrderId = $this->checkAndReturnChargeMetadataOrderId($stripeCharge);
        $this->checkChargeStatus($stripeCharge);
        $stripeChargeId = $stripeCharge['id'];
        $stripeAmount = $stripeCharge['amount'];

        $stripeCharge = $this->stripeChargeRepository->findOneByStripePaymentId($stripeChargeId);

        if (!$stripeCharge) {
            $stripeCharge = new StripeCharge();
            $stripeCharge
                ->setStripeChargeId($stripeChargeId)
                ->setStripeAmount($stripeAmount);
        }

        $stripeCharge->setMiraklOrderId($miraklOrderId);

        try {
            $this->stripeChargeRepository->persistAndFlush($stripeCharge);
        } catch (UniqueConstraintViolationException $e) {
            // in case of concurrency
        }

        return 'Payment created';
    }

    /**
     * @param null|string|\Stripe\PaymentIntent $paymentIntent
     */
    private function checkAndReturnPaymentIntentMetadataOrderId($paymentIntent): ?string
    {
        if (null === $paymentIntent) {
            return null;
        }
        
        if (!$paymentIntent instanceof \Stripe\PaymentIntent) {
            $paymentIntent = $this->stripeProxy->paymentIntentRetrieve($paymentIntent);
        }

        return $paymentIntent['metadata'][$this->metadataOrderIdFieldName] ?? null;
    }

    /**
     * @throws \Exception
     */
    private function checkAndReturnChargeMetadataOrderId(\Stripe\Charge $stripeCharge): string
    {
        if (!isset($stripeCharge['metadata'][$this->metadataOrderIdFieldName])) {
            
            // Check that linked payment intent does not contain itself the metadata
            $paymentIntentMetadata = $this->checkAndReturnPaymentIntentMetadataOrderId($stripeCharge->payment_intent);
            if (null !== $paymentIntentMetadata) {
                return $paymentIntentMetadata;
            }

            $message = sprintf('%s not found in charge or PI metadata webhook event', $this->metadataOrderIdFieldName);
            $this->logger->error($message);

            throw new \Exception($message, Response::HTTP_OK);
        }

        if ('' === $stripeCharge['metadata'][$this->metadataOrderIdFieldName]) {
            $message = sprintf('%s is empty in charge metadata webhook event', $this->metadataOrderIdFieldName);
            $this->logger->error($message);

            throw new \Exception($message, Response::HTTP_BAD_REQUEST);
        }

        return $stripeCharge['metadata'][$this->metadataOrderIdFieldName];
    }

    /**
     * @param mixed $stripeObject
     * @throws \Exception
     */
    private function checkChargeStatus($stripeObject)
    {
        $status = $stripeObject['status'] ?? '';

        if (!in_array($status, self::STRIPE_PAYMENT_LISTEN_STATUS, true)) {
            throw new \Exception('Status has not a valid value to be catch', Response::HTTP_OK);
        }
    }
}
