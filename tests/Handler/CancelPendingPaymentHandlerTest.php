<?php

namespace App\Tests\Handler;

use App\Entity\StripeCharge;
use App\Handler\CancelPendingPaymentHandler;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripeChargeRepository;
use App\Tests\StripeWebTestCase;
use App\Utils\StripeProxy;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Stripe\Exception\ApiConnectionException;
use Symfony\Bundle\FrameworkBundle\Console\Application;

class CancelPendingPaymentHandlerTest extends StripeWebTestCase
{

    use RecreateDatabaseTrait;

    /**
     * @var StripeProxy|\PHPUnit\Framework\MockObject\MockObject
     */
    private $stripeProxy;

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

        $this->handler = new CancelPendingPaymentHandler($this->stripeProxy, $this->stripeChargeRepository);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalPaymentIntentExecute()
    {
        $stripeChargeId = 1;

        $message = new CancelPendingPaymentMessage($stripeChargeId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        $this->assertEquals(StripeCharge::CANCELED, $stripePayment->getStatus());
    }

    public function testNominalChargeExecute()
    {
        $stripeChargeId = 3;

        $message = new CancelPendingPaymentMessage($stripeChargeId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        $this->assertEquals(StripeCharge::CANCELED, $stripePayment->getStatus());
    }

    public function testNotFoundPaymentExecute()
    {
        $stripeChargeId = 4;

        $message = new CancelPendingPaymentMessage($stripeChargeId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripeChargeRepository->findOneBy([
            'id' => $stripeChargeId,
        ]);

        $this->assertEquals(StripeCharge::TO_CAPTURE, $stripePayment->getStatus());
    }
}
