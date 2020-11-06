<?php

namespace App\Tests\Controller;

use App\Controller\StripeWebhookEndpoint;
use App\Entity\AccountMapping;
use App\Entity\StripePayment;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripePaymentRepository;
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

        $this->stripePaymentRepository = $this->getMockBuilder(StripePaymentRepository::class)
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

        $stripePaymentIntentId = 'pi_valid';
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'metadata' => [],
                    'status' => StripePayment::TO_CAPTURE
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
        $this->assertEquals("{$this->metadataOrderIdFieldName} not found in metadata webhook event", $response->getContent());
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
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName . "notGod" => 42,
                    ],
                    'status' => StripePayment::TO_CAPTURE
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
        $this->assertEquals("{$this->metadataOrderIdFieldName} not found in metadata webhook event", $response->getContent());
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
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => '',
                    ],
                    'status' => 'requires_payment_method'
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
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertEquals("{$this->metadataOrderIdFieldName} is empty in metadata webhook event", $response->getContent());
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

        $stripePaymentIntentId = 'pi_valid';
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => 42,
                    ],
                    'status' => 'requires_payment_method'
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

        $stripePaymentIntentId = 'pi_valid';
        $orderId = 42;
        $data = [
            'data' => [
                'object' => [
                    'id' => $stripePaymentIntentId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'requires_capture'
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


        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('findOneByStripePaymentId')
            ->with($stripePaymentIntentId)
            ->willReturn(null);

        $expectedPayment = new StripePayment();
        $expectedPayment
            ->setStripePaymentId($stripePaymentIntentId)
            ->setMiraklOrderId($orderId);

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
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded'
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

        $expectedPayment = new StripePayment();
        $expectedPayment
            ->setStripePaymentId($stripeChargeId)
            ->setMiraklOrderId($orderId);

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
                    'id' => $stripeChargeId,
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded'
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

        $expectedPayment = new StripePayment();
        $expectedPayment
            ->setStripePaymentId($stripeChargeId)
            ->setMiraklOrderId($orderId);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }

    public function testHandleStripeWebhookChargeWithPI()
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
                    'id' => $stripeChargeId,
                    'payment_intent' => 'pi_valid',
                    'metadata' => [
                        $this->metadataOrderIdFieldName => $orderId,
                    ],
                    'status' => 'succeeded'
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
            ->with('pi_valid')
            ->willReturn(null);

        $expectedPayment = new StripePayment();
        $expectedPayment
            ->setStripePaymentId('pi_valid')
            ->setMiraklOrderId($orderId);

        $this
            ->stripePaymentRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedPayment);

        $response = $this->controller->handleStripeSellerWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertEquals("Payment created", $response->getContent());
    }
}
