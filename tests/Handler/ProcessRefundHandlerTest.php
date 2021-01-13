<?php

namespace App\Tests\Handler;

use App\Entity\StripeTransfer;
use App\Entity\StripeRefund;
use App\Handler\ProcessRefundHandler;
use App\Message\ProcessRefundMessage;
use App\Message\RefundFailedMessage;
use App\Repository\StripeTransferRepository;
use App\Repository\StripeRefundRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessRefundHandlerTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->stripeRefundRepository = $container->get('doctrine')->getRepository(StripeRefund::class);

        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->handler = new ProcessRefundHandler(
            $container->get('App\Service\MiraklClient'),
            $container->get('App\Service\StripeClient'),
            $this->stripeRefundRepository,
            self::$container->get(MessageBusInterface::class)
        );
        $this->handler->setLogger(new NullLogger());
    }

		private function executeHandler($stripeRefundId) {
				($this->handler)(new ProcessRefundMessage($stripeRefundId));
		}

		private function mockRefund($refundId, $transactionId) {
				$orderId = MiraklMock::getOrderIdFromRefundId($refundId);

        $refund = new StripeRefund();
				$refund->setMiraklOrderId($orderId);
				$refund->setMiraklRefundId($refundId);
				$refund->setTransactionId($transactionId);
				$refund->setAmount(1234);
				$refund->setCurrency('eur');
				$refund->setStatus(StripeRefund::REFUND_PENDING);

				$this->stripeRefundRepository->persistAndFlush($refund);

				return $refund;
		}

    public function testValidRefund()
    {
				$refund = $this->mockRefund(
						MiraklMock::ORDER_REFUND_BASIC,
						StripeMock::CHARGE_BASIC
				);
				$this->executeHandler($refund->getId());

				$refund = $this->stripeRefundRepository->findOneBy([
						'id' => $refund->getId()
				]);

				$this->assertEquals(StripeRefund::REFUND_CREATED, $refund->getStatus());
				$this->assertEquals(StripeMock::REFUND_BASIC, $refund->getStripeRefundId());
				$this->assertNotNull($refund->getMiraklValidationTime());
    }

    public function testRefundWithStripeError()
    {
				$refund = $this->mockRefund(
						MiraklMock::ORDER_REFUND_BASIC,
						StripeMock::CHARGE_REFUNDED
				);
				$this->executeHandler($refund->getId());

				$refund = $this->stripeRefundRepository->findOneBy([
						'id' => $refund->getId()
				]);

				$this->assertEquals(StripeRefund::REFUND_FAILED, $refund->getStatus());
        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => $refund->getId(),
                    'amount' => $refund->getAmount(),
                    'currency' => $refund->getCurrency(),
                    'miraklOrderId' => $refund->getMiraklOrderId(),
                    'transactionId' => StripeMock::CHARGE_REFUNDED,
                    'miraklRefundId' => MiraklMock::ORDER_REFUND_BASIC,
                    'stripeRefundId' => null,
                    'status' => StripeRefund::REFUND_FAILED,
                    'failedReason' => StripeMock::CHARGE_REFUNDED . ' already refunded',
                ]
            ]
        ));
    }

    public function testRefundWithMiraklError()
    {
				$refund = $this->mockRefund(
						MiraklMock::ORDER_REFUND_VALIDATED,
						StripeMock::CHARGE_BASIC
				);
				$this->executeHandler($refund->getId());

				$refund = $this->stripeRefundRepository->findOneBy([
						'id' => $refund->getId()
				]);

				$this->assertEquals(StripeRefund::REFUND_FAILED, $refund->getStatus());
        $this->assertTrue($this->hasNotification(
            RefundFailedMessage::class,
            [
                'type' => 'refund.failed',
                'payload' => [
                    'internalId' => $refund->getId(),
                    'amount' => $refund->getAmount(),
                    'currency' => $refund->getCurrency(),
                    'miraklOrderId' => $refund->getMiraklOrderId(),
                    'transactionId' => StripeMock::CHARGE_BASIC,
                    'miraklRefundId' => MiraklMock::ORDER_REFUND_VALIDATED,
                    'stripeRefundId' => StripeMock::REFUND_BASIC,
                    'status' => StripeRefund::REFUND_FAILED,
                    'failedReason' => MiraklMock::ORDER_REFUND_VALIDATED . ' already validated',
                ]
            ]
        ));
    }

    private function hasNotification($class, $content)
    {
        foreach ($this->httpNotificationReceiver->get() as $messageEnvelope) {
            $message = $messageEnvelope->getMessage();
            if ($message instanceof $class && $message->getContent() == $content) {
                return true;
            }
        }

        return false;
    }
}
