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

    private function executeHandler($paymentMappingId)
    {
				($this->handler)(new CapturePendingPaymentMessage($paymentMappingId, 100));
    }

    private function mockPaymentMapping($orderId, $chargeId)
    {
        $mapping = new PaymentMapping();
				$mapping->setMiraklOrderId($orderId);
				$mapping->setStripeChargeId($chargeId);

				$this->paymentMappingRepository->persistAndFlush($mapping);

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

    public function testCapturePaymentIntentWithApiError()
    {
        $mapping = $this->mockPaymentMapping(
						MiraklMock::ORDER_BASIC,
						StripeMock::PAYMENT_INTENT_STATUS_SUCCEEDED
				);

        $this->executeHandler($mapping->getId());

        $mapping = $this->getPaymentMapping($mapping->getId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $mapping->getStatus());
    }

    public function testCaptureChargeWithApiError()
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
