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
        $this->assertCount(1, $this->doctrineReceiver->getSent());
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
        $this->assertCount(2, $this->doctrineReceiver->getSent());
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

    public function testRetryFailedTransfer()
    {
        $commandTester = new CommandTester($this->command);

        $failedStripeTransfer = new StripeTransfer();
        $failedStripeTransfer
            ->setType(StripeTransfer::TRANSFER_ORDER)
            ->setStatus(StripeTransfer::TRANSFER_FAILED)
            ->setMiraklId('order_failed_transfer')
            ->setAmount('24')
            ->setCurrency('EUR')
            ->setMiraklUpdateTime(new \DateTime("2019-01-01"));
        $this->stripeTransferRepository->persistAndFlush($failedStripeTransfer);

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

        $createdStripeTransfer = new StripeTransfer();
        $createdStripeTransfer
            ->setType(StripeTransfer::TRANSFER_ORDER)
            ->setStatus(StripeTransfer::TRANSFER_CREATED)
            ->setMiraklId('order_created_transfer')
            ->setAmount('24')
            ->setCurrency('EUR')
            ->setMiraklUpdateTime(new \DateTime("2019-01-01"));
        $this->stripeTransferRepository->persistAndFlush($createdStripeTransfer);

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
}
