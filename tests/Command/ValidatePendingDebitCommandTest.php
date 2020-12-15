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
    protected $stripeChargeRepository;

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

        $this->stripeChargeRepository = self::$container->get('doctrine')->getRepository(StripeCharge::class);
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
        $this->assertCount(5, $captureMessages, 'Incorrect capture message count');
        $this->assertCount(1, $cancelMessages, 'Incorrect cancel message count');

        $ordersToValidate = $validateMessages[0]->getMessage()->getOrders();
        $this->assertEquals(['Order_66', 'Order_42'], array_keys($ordersToValidate));
        $this->assertCount(2, $ordersToValidate['Order_66']);

        $captureMessage = $captureMessages[0]->getMessage();
        $this->assertEquals(1, $captureMessage->getstripeChargeId());
        $this->assertEquals(33000, $captureMessage->getAmount(), 'Invalid amount captured for Order_66');

        $captureMessage = $captureMessages[1]->getMessage();
        $this->assertEquals(3, $captureMessage->getstripeChargeId());
        $this->assertEquals(33000, $captureMessage->getAmount(), 'Invalid amount captured for Order_11');

        $captureMessage = $captureMessages[2]->getMessage();
        $this->assertEquals(5, $captureMessage->getstripeChargeId());
        $this->assertEquals(10000, $captureMessage->getAmount(), 'Invalid amount captured for Order_op_01');

        $captureMessage = $captureMessages[3]->getMessage();
        $this->assertEquals(6, $captureMessage->getstripeChargeId());
        $this->assertEquals(7000, $captureMessage->getAmount(), 'Invalid amount captured for Order_op_02');

        $captureMessage = $captureMessages[4]->getMessage();
        $this->assertEquals(7, $captureMessage->getstripeChargeId());
        $this->assertEquals(2000, $captureMessage->getAmount(), 'Invalid amount captured for Order_op_03');

        $cancelMessage = $cancelMessages[0]->getMessage();
        $this->assertEquals(2, $cancelMessage->getstripeChargeId());
        $this->assertEquals(66000, $cancelMessage->getAmount(), 'Invalid amount cancelled for Order_42');
    }

    public function testNominalNoPayment()
    {
        foreach ($this->stripeChargeRepository->findPendingPayments() as $payment) {
            $payment->capture();
            $this->stripeChargeRepository->persist($payment);
        }

        $this->stripeChargeRepository->flush();

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
