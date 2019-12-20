<?php

namespace App\Tests\Controller;

use App\Controller\StripeWebhookEndpoint;
use App\Entity\MiraklStripeMapping;
use App\Message\AccountUpdateMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Utils\StripeProxy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class StripeWebhookEndpointTest extends TestCase
{
    protected $bus;
    protected $controller;
    protected $miraklStripeMappingRepository;
    protected $stripeProxy;

    protected function setUp(): void
    {
        $this->bus = $this->getMockBuilder(MessageBusInterface::class)
            ->disableOriginalConstructor()
            ->setMethods(['dispatch'])
            ->getMock();

        $this->stripeProxy = $this->createMock(StripeProxy::class);

        $this->miraklStripeMappingRepository = $this->getMockBuilder(MiraklStripeMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeAccountId', 'persistAndFlush'])
            ->getMock();
        $logger = new NullLogger();

        $this->controller = new StripeWebhookEndpoint(
            $this->bus,
            $this->stripeProxy,
            $this->miraklStripeMappingRepository
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

        $response = $this->controller->handleStripeWebhook($request);
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

        $response = $this->controller->handleStripeWebhook($request);
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

        $response = $this->controller->handleStripeWebhook($request);
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
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn(null);

        $response = $this->controller->handleStripeWebhook($request);
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

        $expectedMapping = new MiraklStripeMapping();
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
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with($stripeAccountId)
            ->willReturn($expectedMapping);
        $this
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->bus
            ->expects($this->once())
            ->method('dispatch')
            ->with($dispatchedMessage)
            ->willReturn(new Envelope($dispatchedMessage));

        $response = $this->controller->handleStripeWebhook($request);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertTrue($expectedMapping->getPayinEnabled());
        $this->assertTrue($expectedMapping->getPayoutEnabled());
    }
}
