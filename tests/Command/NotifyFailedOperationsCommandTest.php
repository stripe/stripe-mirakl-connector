<?php

namespace App\Tests\Command;

use App\Command\NotifyFailedOperationsCommand;
use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Repository\MiraklRefundRepository;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Tests\TestCase;
use Symfony\Component\Console\Formatter\OutputFormatterInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;

class NotifyFailedOperationsCommandTest extends TestCase
{
    protected $command;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->transferRepository = $this->createMock(StripeTransferRepository::class);
        $this->payoutRepository = $this->createMock(StripePayoutRepository::class);
        $this->refundRepository = $this->createMock(MiraklRefundRepository::class);

        $this->input = $this->createMock(InputInterface::class);
        $this->output = $this->createMock(OutputInterface::class);
        $this->output
            ->method('getFormatter')
            ->willReturn($this->createMock(OutputFormatterInterface::class));

        $this->command = new NotifyFailedOperationsCommand($this->mailer, $this->transferRepository, $this->payoutRepository, $this->refundRepository, 'mailfrom@example.com', 'mailto@example.com');
        $this->command->setLogger(new NullLogger());
    }

    public function testExecuteWithNoFailedOperation()
    {
        $this->transferRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([]);
        $this->payoutRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([]);
        $this->refundRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([]);
        $this->mailer
            ->expects($this->never())
            ->method('send');

        $resultCode = $this->command->execute($this->input, $this->output);
        $this->assertEquals(0, $resultCode);
    }

    public function testExecuteWithFailedOperations()
    {
        $failedTransfers = $this->createMock(StripeTransfer::class);
        $failedPayouts = $this->createMock(StripePayout::class);
        $failedRefunds = $this->createMock(MiraklRefund::class);
        $this->transferRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([$failedTransfers]);
        $this->payoutRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([$failedPayouts]);
        $this->refundRepository
                ->expects($this->once())
                ->method('findBy')
                ->willReturn([$failedRefunds]);
        $this->mailer
            ->expects($this->once())
            ->method('send');

        $resultCode = $this->command->execute($this->input, $this->output);
        $this->assertEquals(0, $resultCode);
    }
}
