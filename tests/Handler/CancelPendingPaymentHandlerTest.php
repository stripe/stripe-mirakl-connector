<?php

namespace App\Tests\Handler;

use App\Entity\PaymentMapping;
use App\Handler\CancelPendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CancelPendingPaymentMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\StripeClient;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class CancelPendingPaymentHandlerTest extends KernelTestCase
{

    use RecreateDatabaseTrait;

    /**
     * @var StripeClient
     */
    private $stripeClient;

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

        $this->handler = new CancelPendingPaymentHandler(
            $this->stripeClient,
            $this->paymentMappingRepository
        );
        $this->handler->setLogger(new NullLogger());
    }

    private function executeHandler($paymentMappingId)
    {
        ($this->handler)(new CancelPendingPaymentMessage($paymentMappingId, 100));
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

    public function testCancelPaymentIntent()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_REQUIRES_CAPTURE
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CANCELED, $mapping->getStatus());
    }

    public function testCancelCharge()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::CHARGE_STATUS_AUTHORIZED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::CANCELED, $mapping->getStatus());
    }

    public function testCancelPaymentIntentWithApiError()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::PAYMENT_INTENT_STATUS_SUCCEEDED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $mapping->getStatus());
    }

    public function testCancelChargeWithApiError()
    {
        $mapping = $this->mockPaymentMapping(
            MiraklMock::ORDER_BASIC,
            StripeMock::CHARGE_STATUS_CAPTURED
        );

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $mapping->getStatus());
    }
}
