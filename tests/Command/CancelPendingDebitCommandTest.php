<?php

namespace App\Tests\Command;

use App\Repository\StripePaymentRepository;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class CancelPendingDebitCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var \Symfony\Component\Console\Command\Command
     */
    protected $command;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    protected $cancelDoctrineReceiver;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:cancel:pending-debit');

        $this->cancelDoctrineReceiver = self::$container->get('messenger.transport.cancel_pending_payment');

        $this->stripePaymentRepository = $this->getMockBuilder(StripePaymentRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findPendingPaymentByOrderIds', 'persistAndFlush'])
            ->getMock();
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());

        $cancelMessages = $this->cancelDoctrineReceiver->getSent();

        $this->assertCount(1, $cancelMessages);

        $cancelMessage = $cancelMessages[0]->getMessage();

        $this->assertEquals(2, $cancelMessage->getStripePaymentId());
        $this->assertEquals(66000, $cancelMessage->getAmount());
    }

}
