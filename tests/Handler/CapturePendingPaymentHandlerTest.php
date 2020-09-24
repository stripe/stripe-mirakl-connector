<?php

namespace App\Tests\Handler;

use App\Entity\StripePayment;
use App\Exception\InvalidStripeAccountException;
use App\Factory\MiraklPatchShopFactory;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Handler\UpdateKYCStatusHandler;
use App\Handler\ValidateMiraklOrderHandler;
use App\Message\AccountUpdateMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripePaymentRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Account;
use Stripe\Exception\ApiConnectionException;

class CapturePendingPaymentHandlerTest extends TestCase
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
        $this->stripeProxy = $this->createMock(StripeProxy::class);

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

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('capture');

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

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('capture');

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

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('capture');

        $message = new CapturePendingPaymentMessage($stripePayment, 42000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalChargeErrorExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('ch_invalid')
            ->setMiraklOrderId('Order_66');

        $this
            ->stripeProxy
            ->expects($this->once())
            ->method('capture');

        $message = new CapturePendingPaymentMessage($stripePayment, 42000);
        $handler = $this->handler;
        $handler($message);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }
}
