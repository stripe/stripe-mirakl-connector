<?php

namespace App\Tests\Command;

use App\Entity\StripeTransfer;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class ProcessTransferCommandIntegrationTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-transfer');

        $this->doctrineReceiver = self::$container->get('messenger.transport.process_transfers');

        $this->stripeTransferRepository = self::$container->get('doctrine')->getRepository(StripeTransfer::class);
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(1, $this->doctrineReceiver->getMessageCount());
        $this->assertEquals(4, count($stripeTransfersPending));
    }

    public function testExecuteWithArguments()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['order_5', 'order_6'],
        ]);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(2, $this->doctrineReceiver->getMessageCount());
        $this->assertEquals(5, count($stripeTransfersPending));
    }

    public function testValidTransferAmount()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName()
        ]);

        $stripeTransfer = $this->stripeTransferRepository->findOneBy([
          'miraklId' => 'order_4'
        ]);

        // Transfer amount should be 24 - 5 (commission) + 1 + 2 (shipping taxes) + 1 + 2 (taxes)
        $this->assertEquals(2500, $stripeTransfer->getAmount());
    }
}
