<?php

namespace App\Tests\Handler;

use App\Entity\StripePayment;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripePaymentRepository;
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
     * @var StripePaymentRepository
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

        $this->stripePaymentRepository = $container->get('doctrine')->getRepository(StripePayment::class);

        $this->handler = new CapturePendingPaymentHandler($this->stripeProxy, $this->stripePaymentRepository);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalPaymentIntentExecute()
    {
        $stripePaymentId = 1;

        $message = new CapturePendingPaymentMessage($stripePaymentId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalChargeExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('ch_valid')
            ->setMiraklOrderId('Order_66');

        $this->stripePaymentRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripePayment::CAPTURED, $stripePayment->getStatus());
    }

    public function testNominalPaymentIntentErrorExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('pi_invalid')
            ->setMiraklOrderId('Order_66');

        $this->stripePaymentRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 42000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripePayment::TO_CAPTURE, $stripePayment->getStatus());
    }

    public function testNominalChargeErrorExecute()
    {
        $stripePayment = new StripePayment();
        $stripePayment
            ->setStripePaymentId('ch_invalid')
            ->setMiraklOrderId('Order_66');

        $this->stripePaymentRepository->persistAndFlush($stripePayment);

        $message = new CapturePendingPaymentMessage($stripePayment->getId(), 42000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePayment->getId(),
        ]);

        $this->assertEquals(StripePayment::TO_CAPTURE, $stripePayment->getStatus());
    }
}
