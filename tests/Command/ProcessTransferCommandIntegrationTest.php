<?php

namespace App\Tests\Command;

use App\Entity\StripeTransfer;
use App\Repository\StripeTransferRepository;
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
        $this->assertCount(1, $this->doctrineReceiver->getSent());
        $this->assertCount(4, $stripeTransfersPending);
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
        $this->assertCount(2, $this->doctrineReceiver->getSent());
        $this->assertCount(5, $stripeTransfersPending);
    }

    public function testValidTransferAmount()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName()
        ]);

        $stripeTransfer = $this->stripeTransferRepository->findOneBy([
          'miraklId' => 'order_3'
        ]);

        // Transfer amount should be 24 - 5 (commission) + 1 + 2 (shipping taxes) + 1 + 2 (taxes)
        $this->assertEquals(24, $stripeTransfer->getAmount());
    }

    public function testRetryFailedTransfer()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['order_failed_transfer', 'new_order_1']
        ]);

        // Failed transfer should be retried
        $retriedStripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'order_failed_transfer'
        ]);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $retriedStripeTransfer->getStatus());

        // Subsequent transfer should not have failed
        $subsequentStripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'new_order_1'
        ]);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $subsequentStripeTransfer->getStatus());

        // Two messages should be sent
        $this->assertCount(2, $this->doctrineReceiver->getSent());
    }

    public function testRetryCreatedTransfer()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['order_created_transfer', 'new_order_2']
        ]);

        // Created transfer shouldn't be retried
        $stripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'order_created_transfer'
        ]);
        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $stripeTransfer->getStatus());

        // Subsequent transfer should not have failed
        $subsequentStripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'new_order_2'
        ]);
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $subsequentStripeTransfer->getStatus());

        // Only one message should be sent
        $this->assertCount(1, $this->doctrineReceiver->getSent());
    }

    public function testFailedTransferAlreadyProcessed()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['order_already_processed']
        ]);

        // Transfer should be transitioned back to created
        $stripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'order_already_processed'
        ]);
        $this->assertEquals(StripeTransfer::TRANSFER_CREATED, $stripeTransfer->getStatus());

        // No message should be sent
        $this->assertCount(0, $this->doctrineReceiver->getSent());
    }

    public function testIntegrateOldFailedTransfert()
    {
        $failedTransfert = (new StripeTransfer())
            ->setStatus(StripeTransfer::TRANSFER_FAILED)
            ->setAmount(24)
            ->setMiraklUpdateTime(new \DateTime('2018-01-01'))
            ->setCurrency('EUR')
            ->setMiraklId('old_order_failed_transfer')
            ->setTransactionId('ch_transaction_8')
            ->setType('TRANSFER_ORDER')
            ;

        // add olf failed transfert
        $this->stripeTransferRepository->persistAndFlush($failedTransfert);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $stripeTransfer = $this->stripeTransferRepository->findOneBy([
            'miraklId' => 'old_order_failed_transfer'
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        // must be testNominalExecute() number + 1 old failed transfert
        $this->assertCount(2, $this->doctrineReceiver->getSent());
        $this->assertEquals(StripeTransfer::TRANSFER_PENDING, $stripeTransfer->getStatus());
    }

    public function testNolastMiraklUpdateTime()
    {
        // Suppress all transfert
        $this->stripeTransferRepository
            ->createQueryBuilder('t')
            ->delete(StripeTransfer::class, 't')
            ->where('t.miraklUpdateTime is not null')
            ->getQuery()
            ->execute()
        ;

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertCount(0, $stripeTransfersPending);

        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertCount(1, $this->doctrineReceiver->getSent());
        $this->assertCount(1, $stripeTransfersPending);
    }

    public function testTransfertWithNegativeAmount()
    {
        $commandTester = new CommandTester($this->command);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertCount(3, $stripeTransfersPending);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_FAILED
        ]);

        $this->assertCount(4, $stripeTransfersPending);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['order_11']
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertCount(1, $this->doctrineReceiver->getSent());

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_PENDING
        ]);

        $this->assertCount(3, $stripeTransfersPending);

        $stripeTransfersPending = $this->stripeTransferRepository->findBy([
            'status' => StripeTransfer::TRANSFER_FAILED
        ]);

        $this->assertCount(5, $stripeTransfersPending);
    }

    public function testTransfertWithPendingPaiment()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['Order_66']
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertCount(0, $this->doctrineReceiver->getSent());
    }

    public function testTransfertWithOrderBadState()
    {
        $commandTester = new CommandTester($this->command);

        $commandTester->execute([
            'command' => $this->command->getName(),
            'mirakl_order_ids' => ['Order_51']
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertCount(0, $this->doctrineReceiver->getSent());
    }
}
