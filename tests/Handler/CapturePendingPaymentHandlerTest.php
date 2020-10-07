<?php

namespace App\Tests\Handler;

use App\Entity\StripePayment;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripePaymentRepository;
use App\Tests\StripeWebTestCase;
use App\Utils\StripeProxy;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiConnectionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;

class CapturePendingPaymentHandlerTest extends StripeWebTestCase
{

    /**
     * @var StripeProxy|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stripeProxy;

    /**
     * @var \PHPUnit\Framework\MockObject\MockObject
     */
    private $stripePaymentRepository;

    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->stripeProxy = $container->get('App\Utils\StripeProxy');

        $this->stripePaymentRepository = $this->getMockBuilder(StripePaymentRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['persistAndFlush', 'findOneBy'])
            ->getMock();

        $this->handler = new CapturePendingPaymentHandler($this->stripeProxy, $this->stripePaymentRepository);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalPaymentIntentExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('pi_valid')
            ->setMiraklOrderId('Order_66');

        $message = new CapturePendingPaymentMessage($stripePayment, 33000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalChargeExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('ch_valid')
            ->setMiraklOrderId('Order_66');

        $message = new CapturePendingPaymentMessage($stripePayment, 33000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalPaymentIntentErrorExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('pi_invalid')
            ->setMiraklOrderId('Order_66');

        $message = new CapturePendingPaymentMessage($stripePayment, 42000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::TO_CAPTURE, $stripePayment->getStatus());
    }

    public function testNominalChargeErrorExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('ch_invalid')
            ->setMiraklOrderId('Order_66');

        $message = new CapturePendingPaymentMessage($stripePayment, 42000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::TO_CAPTURE, $stripePayment->getStatus());
    }
}
