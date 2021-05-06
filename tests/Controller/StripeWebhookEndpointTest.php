<?php

namespace App\Tests\Controller;

use App\Controller\StripeWebhookEndpoint;
use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
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
    protected $paymentMappingRepository;

    /**
     * @var StripeClient|\PHPUnit\Framework\MockObject\MockObject
     */
    protected $stripeClient;

    /**
     * @var string
     */
    protected $metadataCommercialOrderId;

    protected function setUp(): void
    {
        $this->metadataCommercialOrderId = 'mirakl_order_id';
        $this->bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['dispatch'])
            ->getMock();

        $this->stripeClient = $this->createMock(StripeClient::class);

        $this->accountMappingRepository = $this->getMockBuilder(AccountMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeAccountId', 'persist', 'flush'])
            ->getMock();

        $this->paymentMappingRepository = $this->getMockBuilder(PaymentMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeChargeId', 'persist', 'flush'])
            ->getMock();

        $logger = new NullLogger();

        $this->controller = new StripeWebhookEndpoint(
            $this->bus,
            $this->stripeClient,
            $this->accountMappingRepository,
            $this->paymentMappingRepository,
            $this->metadataCommercialOrderId
        );
        $this->controller->setLogger($logger);
    }

    private function mockPaymentMapping(string $orderId, string $chargeId, bool $captured = false)
    {
        $paymentMapping = new PaymentMapping();
				$paymentMapping->setMiraklCommercialOrderId($orderId);
				$paymentMapping->setStripeChargeId($chargeId);
				$paymentMapping->setStatus($captured ? PaymentMapping::CAPTURED : PaymentMapping::TO_CAPTURE);

				$this->paymentMappingRepository->persist($paymentMapping);
				$this->paymentMappingRepository->flush();

				return $paymentMapping;
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
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->will($this->throwException(new \UnexpectedValueException()));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Invalid payload.', $response->getContent());
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
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->will($this->throwException(new \Stripe\Exception\SignatureVerificationException()));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals('Invalid signature.', $response->getContent());
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
            ->stripeClient
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
            ->stripeClient
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
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('Ignoring account.updated event for non-Mirakl Stripe account.', $response->getContent());
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
            ->stripeClient
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
            ->stripeClient
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
            ->stripeClient
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

        $data = [
            'data' => [
                'object' => [
                    'id' => 'pi_valid',
                    'metadata' => [],
                    'status' => PaymentMapping::TO_CAPTURE
                ],
            ],
            'type' => 'payment_intent.created',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("The event type payment_intent.created is no longer required and can be removed in the webhook settings.", $response->getContent());
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
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('Ignoring event with no Mirakl Commercial Order ID.', $response->getContent());
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
                        $this->metadataCommercialOrderId . "notGod" => 42,
                    ],
                    'status' => 'pending'
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('Ignoring event with no Mirakl Commercial Order ID.', $response->getContent());
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
                        $this->metadataCommercialOrderId => '',
                    ],
                    'status' => 'pending'
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals("{$this->metadataCommercialOrderId} is empty in Charge metadata.", $response->getContent());
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
                        $this->metadataCommercialOrderId => 42,
                    ],
                    'status' => 'failed',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals('Ignoring failed charge event.', $response->getContent());
    }

    public function testHandleStripeWebhookChargeAlreadyCaptured()
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
        $orderId = '42';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataCommercialOrderId => $orderId,
                    ],
                    'status' => 'succeeded',
                    'captured' => true,
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn($this->mockPaymentMapping($orderId, $stripeChargeId, true));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment mapping updated.", $response->getContent());
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

        $stripeChargeId = 'ch_valid';
        $orderId = '42';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => null,
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataCommercialOrderId => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn($this->mockPaymentMapping($orderId, $stripeChargeId));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment mapping updated.", $response->getContent());
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

        $stripeChargeId = 'ch_valid';
        $orderId = '42';
        $data = [
            'data' => [
                'object' => [
                    'object' => 'charge',
                    'payment_intent' => [
                        'object' => 'payment_intent',
                        'id' => 'pi_something',
                        'metadata' => [
                            $this->metadataCommercialOrderId => $orderId,
                        ]
                    ],
                    'id' => $stripeChargeId,
                    'metadata' => null,
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn($this->mockPaymentMapping($orderId, $stripeChargeId));

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment mapping updated.", $response->getContent());
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
        $orderId = '42';
        $stripePaymentIntent = [
            'object' => 'payment_intent',
            'id' => $stripePaymentIntentId,
            'metadata' => [
                $this->metadataCommercialOrderId => $orderId
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
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('paymentIntentRetrieve')
            ->with($stripePaymentIntentId)
            ->willReturn(\Stripe\PaymentIntent::constructFrom($stripePaymentIntent));

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new PaymentMapping();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklCommercialOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('persist')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals("Payment mapping created.", $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
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
                        $this->metadataCommercialOrderId => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.succeeded',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new PaymentMapping();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklCommercialOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('persist')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment mapping created.", $response->getContent());
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
                        $this->metadataCommercialOrderId => $orderId,
                    ],
                    'status' => 'succeeded',
                    'amount' => 2000
                ],
            ],
            'type' => 'charge.updated',
        ];
        $expectedEvent = \Stripe\Event::constructFrom($data);

        $this
            ->stripeClient
            ->expects($this->once())
            ->method('webhookConstructEvent')
            ->with($payload, $signature)
            ->willReturn($expectedEvent);


        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeChargeId')
            ->with($stripeChargeId)
            ->willReturn(null);

        $expectedPayment = new PaymentMapping();
        $expectedPayment
            ->setStripeChargeId($stripeChargeId)
            ->setMiraklCommercialOrderId($orderId)
            ->setStripeAmount(2000);

        $this
            ->paymentMappingRepository
            ->expects($this->once())
            ->method('persist')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment mapping created.", $response->getContent());
    }
}
