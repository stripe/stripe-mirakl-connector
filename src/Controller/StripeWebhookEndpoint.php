<?php

namespace App\Controller;

use App\Entity\StripePayment;
use App\Message\AccountUpdateMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripePaymentRepository;
use App\Utils\StripeProxy;
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

    const HANDLED_EVENT_TYPES = [
        'account.updated',
        'payment_intent.created',
        'payment_intent.succeeded',
        'charge.succeeded',
        'charge.updated'
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

    /**
     * @var StripePaymentRepository
     */
    private $stripePaymentRepository;

    /**
     * @var string
     */
    private $metadataOrderIdFieldName;

    public function __construct(
        MessageBusInterface $bus,
        StripeProxy $stripeProxy,
        MiraklStripeMappingRepository $miraklStripeMappingRepository,
        StripePaymentRepository $stripePaymentRepository,
        string $metadataOrderIdFieldName
    )
    {
        $this->bus = $bus;
        $this->stripeProxy = $stripeProxy;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
        $this->stripePaymentRepository = $stripePaymentRepository;
        $this->metadataOrderIdFieldName = $metadataOrderIdFieldName;
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

        $status = Response::HTTP_OK;
        try {
            switch ($event->type) {
                case 'account.updated':
                    $message = $this->onAccountUpdated($event);
                    break;
                case 'payment_intent.created':
                case 'payment_intent.succeeded':
                case 'charge.succeeded':
                case 'charge.updated':
                    $message = $this->onPaymentIntentOrChargeCreated($event);
                    break;
                default:
                    // should never be trigger
                    $message = 'Not managed yet';
                    $status = Response::HTTP_BAD_REQUEST;
                    break;
            }
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $status = $e->getCode();
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
     * @param Event $event
     * @return string
     * @throws \Exception
     */
    private function onAccountUpdated(Event $event): string
    {
        $stripeAccount = $event->data->object;

        $miraklStripeMapping = $this->miraklStripeMappingRepository->findOneByStripeAccountId($stripeAccount['id']);
        if (!$miraklStripeMapping) {
            $this->logger->error(sprintf('This Stripe Account does not exist %s', $stripeAccount['id']));

            throw new \Exception('This Stripe Account does not exist', Response::HTTP_BAD_REQUEST);
        }

        $miraklStripeMapping
            ->setPayoutEnabled($stripeAccount['payouts_enabled'])
            ->setPayinEnabled($stripeAccount['charges_enabled'])
            ->setDisabledReason($stripeAccount['disabled_reason']);

        $this->miraklStripeMappingRepository->persistAndFlush($miraklStripeMapping);

        $this->bus->dispatch(new AccountUpdateMessage($miraklStripeMapping->getMiraklShopId(), $stripeAccount['id']));

        return 'Account updated';
    }

    /**
     * @param Event $event
     * @return string
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    private function onPaymentIntentOrChargeCreated(Event $event): string
    {
        $apiStripePayment = $event->data->object;

        $miraklOrderId = $this->checkAndReturnMetadataOrderId($apiStripePayment);
        $this->checkPaymentIntentOrChargeStatus($apiStripePayment);
        $stripePaymentId = $apiStripePayment['id'];

        $stripePayment = $this->stripePaymentRepository->findOneByStripePaymentId($stripePaymentId);

        if (!$stripePayment) {
            $stripePayment = new StripePayment();
            $stripePayment
                ->setMiraklOrderId($miraklOrderId)
                ->setStripePaymentId($stripePaymentId);
        }

        $this->stripePaymentRepository->persistAndFlush($stripePayment);

        return 'Payment created';
    }

    /**
     * @param mixed $stripeObject
     * @return string
     * @throws \Exception
     */
    private function checkAndReturnMetadataOrderId($stripeObject): string
    {
        if (!isset($stripeObject['metadata'][$this->metadataOrderIdFieldName])) {
            $message = sprintf('%s not found in metadata webhook event', $this->metadataOrderIdFieldName);
            $this->logger->error($message);

            throw new \Exception($message, Response::HTTP_BAD_REQUEST);
        }

        return $stripeObject['metadata'][$this->metadataOrderIdFieldName];
    }

    /**
     * @param mixed $stripeObject
     * @throws \Exception
     */
    private function checkPaymentIntentOrChargeStatus($stripeObject)
    {
        $status = $stripeObject['status'] ?? '';

        if (!in_array($status, StripePayment::ALLOWED_STATUS, true)) {
            throw new \Exception('Status has not a valid value', Response::HTTP_BAD_REQUEST);
        }
    }

}
