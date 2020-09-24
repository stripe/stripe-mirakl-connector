<?php

namespace App\Tests\Command;

use App\Entity\StripePayment;
use App\Repository\StripePaymentRepository;
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
     * @var \PHPUnit\Framework\MockObject\MockObject
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

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:validate:pending-debit');

        $this->validateDoctrineReceiver = self::$container->get('messenger.transport.validate_mirakl_order');
        $this->captureDoctrineReceiver = self::$container->get('messenger.transport.capture_pending_payment');

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
        $this->assertCount(1, $this->validateDoctrineReceiver->getSent());
        $this->assertCount(1, $this->captureDoctrineReceiver->getSent());
    }

}
