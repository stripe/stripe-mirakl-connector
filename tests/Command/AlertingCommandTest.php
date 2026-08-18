<?php

namespace App\Tests\Command;

use App\Command\AlertingCommand;
use App\Entity\PaymentMapping;
use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Entity\StripeRefund;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Repository\StripeRefundRepository;
use Psr\Log\NullLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Console\Formatter\OutputFormatter;


class AlertingCommandTest extends TestCase
{
    protected $mailer;
    protected $paymentMappingRepository;
    protected $transferRepository;
    protected $payoutRepository;
    protected $refundRepository;
    protected $input;
    protected $output;

    protected $command;

    protected function setUp(): void
    {
        $this->mailer = $this->createMock(MailerInterface::class);
        $this->paymentMappingRepository = $this->createMock(PaymentMappingRepository::class);
        $this->transferRepository = $this->createMock(StripeTransferRepository::class);
        $this->payoutRepository = $this->createMock(StripePayoutRepository::class);
        $this->refundRepository = $this->createMock(StripeRefundRepository::class);

        $this->input = $this->createMock(InputInterface::class);
        $this->output = $this->createMock(OutputInterface::class);
        $outputFormatter = new OutputFormatter(false);
        $this->output->setFormatter($outputFormatter);
        $this->output
            ->method('getFormatter')
            ->willReturn($outputFormatter);
        $this->command = new AlertingCommand($this->mailer, $this->paymentMappingRepository, $this->transferRepository, $this->payoutRepository, $this->refundRepository, 'mailfrom@example.com', 'mailto@example.com');
        $this->command->setLogger(new NullLogger());
    }

    /**
     * @return void
     */
    public function testExecuteWithNoFailedOperation()
    {
        $this->paymentMappingRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([]);
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
        $failedPayments = $this->createMock(PaymentMapping::class);
        $failedTransfers = $this->createMock(StripeTransfer::class);
        $failedPayouts = $this->createMock(StripePayout::class);
        $failedRefunds = $this->createMock(StripeRefund::class);
        $this->paymentMappingRepository
            ->expects($this->once())
            ->method('findBy')
            ->willReturn([$failedPayments]);
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
