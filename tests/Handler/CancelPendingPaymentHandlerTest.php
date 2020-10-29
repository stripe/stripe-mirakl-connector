<?php

namespace App\Tests\Handler;

use App\Entity\StripePayment;
use App\Handler\CancelPendingPaymentHandler;
use App\Handler\CapturePendingPaymentHandler;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\CancelPendingPaymentMessage;
use App\Message\CapturePendingPaymentMessage;
use App\Repository\StripePaymentRepository;
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

        $this->handler = new CancelPendingPaymentHandler($this->stripeProxy, $this->stripePaymentRepository);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalPaymentIntentExecute()
    {
        $stripePaymentId = 1;

        $message = new CancelPendingPaymentMessage($stripePaymentId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        $this->assertEquals(StripePayment::CANCELED, $stripePayment->getStatus());
    }

    public function testNominalChargeExecute()
    {
        $stripePaymentId = 3;

        $message = new CancelPendingPaymentMessage($stripePaymentId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        $this->assertEquals(StripePayment::CANCELED, $stripePayment->getStatus());
    }

    public function testNotFoundPaymentExecute()
    {
        $stripePaymentId = 4;

        $message = new CancelPendingPaymentMessage($stripePaymentId, 33000);
        $handler = $this->handler;
        $handler($message);

        $stripePayment = $this->stripePaymentRepository->findOneBy([
            'id' => $stripePaymentId,
        ]);

        $this->assertEquals(StripePayment::TO_CAPTURE, $stripePayment->getStatus());
    }
}
