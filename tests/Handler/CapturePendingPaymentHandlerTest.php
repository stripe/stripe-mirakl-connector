<?php

namespace App\Tests\Handler;

use App\Entity\PaymentMapping;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CapturePendingPaymentHandlerTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->stripeClient = $container->get('App\Service\StripeClient');

        $this->paymentMappingRepository = $container->get('doctrine')->getRepository(PaymentMapping::class);

        $this->handler = new CapturePendingPaymentHandler(
            $this->stripeClient,
            $this->paymentMappingRepository
        );
        $this->handler->setLogger(new NullLogger());
    }

    private function executeHandler($paymentMappingId, ?int $attempt = null)
    {
        ($this->handler)(new CapturePendingPaymentMessage($paymentMappingId, 100, $attempt));
    }

    private function mockPaymentMapping($orderId, $chargeId)
    {
        $mapping = new PaymentMapping();
        $mapping->setMiraklCommercialOrderId($orderId);
        $mapping->setStripeChargeId($chargeId);

        $this->paymentMappingRepository->persist($mapping);
        $this->paymentMappingRepository->flush();

        return $mapping;
    }

    private function getPaymentMapping($id)
    {
        return $this->paymentMappingRepository->findOneBy([
            'id' => $id
        ]);
    }

    public function testCapturePaymentIntent()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CAPTURED, $mapping->getStatus());
    }

    public function testCaptureCharge()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::CHARGE_STATUS_AUTHORIZED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CAPTURED, $mapping->getStatus());
    }

    public function testCapturePaymentIntentAlreadyCapturedIsReconciled()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_SUCCEEDED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CAPTURED, $mapping->getStatus());
        $this->assertNull($mapping->getStatusReason());
    }

    public function testCapturePaymentIntentWithApiError()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD
        );

        $this->executeHandler($mapping->getId(), 0);

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertSame(PaymentMapping::CAPTURE_FAILED, $mapping->getStatus());
        $this->assertNotNull($mapping->getStatusReason());
        $this->assertSame(1, $mapping->getStripeRequestAttempt());

        $this->executeHandler($mapping->getId(), 1);

        $stripeMock = static::getContainer()->get(StripeMock::class);
        $this->assertSame(
            'mirakl-payment-capture-'.$mapping->getId().'-attempt-1',
            $stripeMock->getLastIdempotencyKey()
        );
        $this->assertSame(2, $this->getPaymentMapping($mapping->getId())->getStripeRequestAttempt());
    }

    public function testCaptureConnectionTimeoutReusesAttemptKey(): void
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_CONNECTION_ERROR
        );

        $this->executeHandler($mapping->getId(), 0);
        $this->executeHandler($mapping->getId(), 0);

        $stripeMock = static::getContainer()->get(StripeMock::class);
        $this->assertSame(0, $this->getPaymentMapping($mapping->getId())->getStripeRequestAttempt());
        $this->assertSame(
            'mirakl-payment-capture-'.$mapping->getId().'-attempt-0',
            $stripeMock->getLastIdempotencyKey()
        );
    }

    public function testCaptureChargeAlreadyCaptured()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::CHARGE_STATUS_CAPTURED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CAPTURED, $mapping->getStatus());
    }

    public function testStaleCaptureMessageDoesNotChangeCapturedMapping(): void
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_SUCCEEDED
        );
        $mapping->setStatus(PaymentMapping::CAPTURED);
        $mapping->setStatusReason('Preserve settled state');
        $this->paymentMappingRepository->flush();

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertSame(PaymentMapping::CAPTURED, $mapping->getStatus());
        $this->assertSame('Preserve settled state', $mapping->getStatusReason());
    }
}
