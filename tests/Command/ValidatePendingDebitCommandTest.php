<?php

namespace App\Tests\Command;

use App\Entity\StripeCharge;
use App\Repository\StripeChargeRepository;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class ValidatePendingDebitCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var \Symfony\Component\Console\Command\Command
     */
    protected $command;

    /**
     * @var StripeChargeRepository
     */
    protected $stripePaymentRepository;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $validateDoctrineReceiver;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $captureDoctrineReceiver;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $cancelDoctrineReceiver;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:validate:pending-debit');

        $this->validateDoctrineReceiver = self::$container->get('messenger.transport.validate_mirakl_order');
        $this->captureDoctrineReceiver = self::$container->get('messenger.transport.capture_pending_payment');
        $this->cancelDoctrineReceiver = self::$container->get('messenger.transport.cancel_pending_payment');

        $this->stripePaymentRepository = self::$container->get('doctrine')->getRepository(StripeCharge::class);
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        $validateMessages = $this->validateDoctrineReceiver->getSent();
        $captureMessages = $this->captureDoctrineReceiver->getSent();
        $cancelMessages = $this->cancelDoctrineReceiver->getSent();

        $this->assertCount(1, $validateMessages);
        $this->assertCount(2, $captureMessages);
        $this->assertCount(1, $cancelMessages);

        $ordersToValidate = $validateMessages[0]->getMessage()->getOrders();

        $this->assertEquals(['Order_66', 'Order_42'], array_keys($ordersToValidate));
        $this->assertCount(2, $ordersToValidate['Order_66']);

        $captureMessage = $captureMessages[0]->getMessage();

        $this->assertEquals(1, $captureMessage->getStripePaymentId());
        $this->assertEquals(33000, $captureMessage->getAmount());

        $captureMessage = $captureMessages[1]->getMessage();

        $this->assertEquals(3, $captureMessage->getStripePaymentId());
        $this->assertEquals(33000, $captureMessage->getAmount());

        $cancelMessage = $cancelMessages[0]->getMessage();

        $this->assertEquals(2, $cancelMessage->getStripePaymentId());
        $this->assertEquals(66000, $cancelMessage->getAmount());
    }

    public function testNominalNoPayment()
    {
        foreach ($this->stripePaymentRepository->findPendingPayments() as $payment) {
            $payment->capture();
            $this->stripePaymentRepository->persist($payment);
        }

        $this->stripePaymentRepository->flush();

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        $validateMessages = $this->validateDoctrineReceiver->getSent();
        $captureMessages = $this->captureDoctrineReceiver->getSent();
        $cancelMessages = $this->cancelDoctrineReceiver->getSent();

        $this->assertCount(0, $validateMessages);
        $this->assertCount(0, $captureMessages);
        $this->assertCount(0, $cancelMessages);
    }
}
