<?php

namespace App\Tests\Controller;

use App\Controller\StripeWebhookEndpoint;
use App\Entity\AccountMapping;
use App\Entity\StripeCharge;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripeChargeRepository;
use App\Utils\StripeProxy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class StripeWebhookEndpointTest extends TestCase
{
    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $bus;

    /**
     * @var StripeWebhookEndpoint
     */
    protected $controller;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $accountMappingRepository;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    protected $stripePaymentRepository;

    /**
     * @var StripeProxy|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stripeProxy;

    /**
     * @var string
     */
    protected $metadataOrderIdFieldName;

    protected function setUp(): void
    {
        $this->metadataOrderIdFieldName = 'mirakl_order_id';
        $this->bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['dispatch'])
            ->getMock();

        $this->stripeProxy = $this->createMock(StripeProxy::class);

        $this->accountMappingRepository = $this->getMockBuilder(AccountMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeAccountId', 'persistAndFlush'])
            ->getMock();

        $this->stripePaymentRepository = $this->getMockBuilder(StripeChargeRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripePaymentId', 'persistAndFlush'])
            ->getMock();

        $logger = new NullLogger();

        $this->controller = new StripeWebhookEndpoint(
            $this->bus,
            $this->stripeProxy,
            $this->accountMappingRepository,
            $this->stripePaymentRepository,
            $this->metadataOrderIdFieldName
        );
        $this->controller->setLogger($logger);
    }

    public function testHandleStripeWebhookWithInvalidPayload()
    {
        $payload = 'invalidPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->will($this->throwException(new \UnexpectedValueException()));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Invalid payload', $response->getContent());
    }

    public function testHandleStripeWebhookWithInvalidSignature()
    {
        $payload = 'validPayload';
        $signature = 'invalidSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->will($this->throwException(new \Stripe\Exception\SignatureVerificationException()));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Invalid signature', $response->getContent());
    }

    public function testHandleStripeWebhookWithUnhandledEventType()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeAccountId = 'acct_valid';
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripeAccountId,
                    'payouts_enabled' => true,
                    'payins_enabled' => true,
                ],
            ],
            'type' => 'unhandledEventType',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Unhandled event type', $response->getContent());
    }

    public function testHandleStripeWebhookWithUnknownStripeAccountId()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeAccountId = 'acct_valid';
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripeAccountId,
                    'payouts_enabled' => true,
                    'payins_enabled' => true,
                ],
            ],
            'type' => 'account.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn(null);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('This Stripe Account does not exist', $response->getContent());
    }

    public function testHandleStripeWebhook()
    {
        $payload = 'validPayload';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => 'validSignature'],
            $payload
        );

        $expectedMapping = new AccountMapping();
        $stripeAccountId = 'acct_valid';
        $miraklShopId = 1;
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripeAccountId,
                    'payouts_enabled' => true,
                    'charges_enabled' => true,
                ],
            ],
            'type' => 'account.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);
        $dispatchedMessage = new AccountUpdateMessage($miraklShopId, $stripeAccountId);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, 'validSignature')
            ->willReturn($expectedEvent);
        $expectedMapping
            ->setStripeAccountId($stripeAccountId)
            ->setMiraklShopId($miraklShopId)
            ->setPayoutEnabled(false)
            ->setPayinEnabled(false);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn($expectedMapping);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($dispatchedMessage)
            ->willReturn(new Envelope($dispatchedMessage));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($expectedMapping->getPayinEnabled());
        $this->assertTrue($expectedMapping->getPayoutEnabled());
    }

    public function testHandleStripeOperatorWebhook()
    {
        $payload = 'validPayload';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => 'validSignature'],
            $payload
        );

        $expectedMapping = new AccountMapping();
        $stripeAccountId = 'acct_valid';
        $miraklShopId = 1;
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripeAccountId,
                    'payouts_enabled' => true,
                    'charges_enabled' => true,
                ],
            ],
            'type' => 'account.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);
        $dispatchedMessage = new AccountUpdateMessage($miraklShopId, $stripeAccountId);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, 'validSignature')
            ->willReturn($expectedEvent);
        $expectedMapping
            ->setStripeAccountId($stripeAccountId)
            ->setMiraklShopId($miraklShopId)
            ->setPayoutEnabled(false)
            ->setPayinEnabled(false);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn($expectedMapping);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($dispatchedMessage)
            ->willReturn(new Envelope($dispatchedMessage));

        $response = $this->controller->handleStripeOperatorWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($expectedMapping->getPayinEnabled());
        $this->assertTrue($expectedMapping->getPayoutEnabled());
    }

    public function testHandleStripeDeprecatedWebhook()
    {
        $payload = 'validPayload';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => 'validSignature'],
            $payload
        );

        $expectedMapping = new AccountMapping();
        $stripeAccountId = 'acct_valid';
        $miraklShopId = 1;
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripeAccountId,
                    'payouts_enabled' => true,
                    'charges_enabled' => true,
                ],
            ],
            'type' => 'account.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);
        $dispatchedMessage = new AccountUpdateMessage($miraklShopId, $stripeAccountId);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, 'validSignature')
            ->willReturn($expectedEvent);
        $expectedMapping
            ->setStripeAccountId($stripeAccountId)
            ->setMiraklShopId($miraklShopId)
            ->setPayoutEnabled(false)
            ->setPayinEnabled(false);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn($expectedMapping);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($dispatchedMessage)
            ->willReturn(new Envelope($dispatchedMessage));

        $response = $this->controller->handleStripeWebhookDeprecated($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($expectedMapping->getPayinEnabled());
        $this->assertTrue($expectedMapping->getPayoutEnabled());
    }

    public function testHandleStripePIWebhookWithoutMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripePaymentIntentId = 'pi_valid';
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'metadata' => [],
                    'status' => StripeCharge::TO_CAPTURE
                ],
            ],
            'type' => 'payment_intent.created',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("The event type payment_intent.created is no longer required and can be removed in the operator webhook settings.", $response->getContent());
    }

    public function testHandleStripeWebhookWithoutMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeChargeId = 'ch_valid';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [],
                    'status' => 'pending',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("{$this->metadataOrderIdFieldName} not found in charge or PI metadata webhook event", $response->getContent());
    }

    public function testHandleStripeWebhookWithBadMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripePaymentIntentId = 'pi_valid';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName . "notGod" => 42,
                    ],
                    'status' => 'pending'
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("{$this->metadataOrderIdFieldName} not found in charge or PI metadata webhook event", $response->getContent());
    }

    public function testHandleStripeWebhookWithEmptyMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripePaymentIntentId = 'pi_valid';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => '',
                    ],
                    'status' => 'pending'
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals("{$this->metadataOrderIdFieldName} is empty in charge metadata webhook event", $response->getContent());
    }

    public function testHandleStripeWebhookWithBadStatus()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeChargeId = 'ch_valid';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => 42,
                    ],
                    'status' => 'failed',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Status has not a valid value to be catch", $response->getContent());
    }

    public function testHandleStripeWebhookPICreatedWithtMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripePaymentIntentId = 'ch_valid';
        $orderId = 42;
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripePaymentIntentId)
            ->willReturn(null);

        $expectedPayment = new StripeCharge();
        $expectedPayment
            ->setStripeChargeId($stripePaymentIntentId)
            ->setMiraklOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookChargeCreatedWithMetadataInPI()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripePaymentIntentId = 'ch_valid';
        $orderId = 42;
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => [
                        'object' => 'payment_intent',
                        'id' => 'pi_something',
                        'metadata' => [
                            $this->metadataOrderIdFieldName => $orderId,
                        ]
                    ],
                    'id' => $stripePaymentIntentId,
                    'metadata' => null,
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripePaymentIntentId)
            ->willReturn(null);

        $expectedPayment = new StripeCharge();
        $expectedPayment
            ->setStripeChargeId($stripePaymentIntentId)
            ->setMiraklOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookChargeCreatedWithMetadataInPIToRetrieve()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeChargeId = 'ch_valid';
        $stripePaymentIntentId = 'pi_valid';
        $orderId = 42;
        $stripePaymentIntent = [
                    'object' => 'payment_intent',
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId
            ]
        ];

        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => $stripePaymentIntentId,
                    'id' => $stripeChargeId,
                    'metadata' => null,
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded'
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);
        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('paymentIntentRetrieve')
            ->with($stripePaymentIntentId)
            ->willReturn(\Stripe\PaymentIntent::constructFrom($stripePaymentIntent));

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new StripeCharge();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookChargeSucceededWithtMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeChargeId = 'ch_valid';
        $orderId = 51;
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new StripeCharge();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookChargeUpdatedWithtMetadata()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $stripeChargeId = 'ch_valid';
        $orderId = 12;
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new StripeCharge();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookWithIncorrectEvent()
    {
        $payload = 'validPayload';
        $signature = 'validSignature';
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            [],
            [],
            [],
            ['HTTP_STRIPE_SIGNATURE' => $signature],
            $payload
        );

        $data = [
            'data' => [
                'object' => [
                    'object' => 'payment_intent',
                ],
            ],
            'type' => 'charge.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals("Webhook expected a charge. Received a Stripe\PaymentIntent instead.", $response->getContent());
    }
}
