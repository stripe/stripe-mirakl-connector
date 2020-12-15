<?php

namespace App\Tests\Handler;

use App\Entity\StripeCharge;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripeChargeRepository;
use App\Tests\StripeWebTestCase;
use App\Utils\StripeProxy;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiConnectionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;

class CapturePendingPaymentHandlerTest extends StripeWebTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var StripeProxy|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stripeProxy;

    /**
     * @var StripeChargeRepository
     */
    private $stripeChargeRepository;

    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->stripeProxy = $container->get('App\Utils\StripeProxy');

        $this->stripeChargeRepository = $container->get('doctrine')->getRepository(StripeCharge::class);

        $this->handler = new CapturePendingPaymentHandler($this->stripeProxy, $this->stripeChargeRepository);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalPaymentIntentExecute()
    {
        $stripeChargeId = 1;

        $message = new CapturePendingPaymentMessage($stripeChargeId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        $this->assertEquals(StripeCharge::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalChargeExecute()
    {
        $stripePayment = new StripeCharge();
        $stripePayment
            ->setStripeChargeId('ch_valid')
            ->setMiraklOrderId('Order_66');

        $this->stripeChargeRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripeCharge::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalPaymentIntentErrorExecute()
    {
        $stripePayment = new StripeCharge();
        $stripePayment
            ->setStripeChargeId('pi_invalid')
            ->setMiraklOrderId('Order_66');

        $this->stripeChargeRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 42000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripeCharge::TO_CAPTURE, $stripePayment->getStatus());
    }

    public function testNominalChargeErrorExecute()
    {
        $stripePayment = new StripeCharge();
        $stripePayment
            ->setStripeChargeId('ch_invalid')
            ->setMiraklOrderId('Order_66');

        $this->stripeChargeRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 42000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripeCharge::TO_CAPTURE, $stripePayment->getStatus());
    }
}
