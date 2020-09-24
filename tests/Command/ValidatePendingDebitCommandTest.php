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

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:validate:pending-debit');

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
    }

}
