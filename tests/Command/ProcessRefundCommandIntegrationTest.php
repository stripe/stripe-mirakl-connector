<?php

namespace App\Tests\Command;

use App\Entity\StripeRefund;
use App\Repository\StripeRefundRepository;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class ProcessRefundCommandIntegrationTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var object|\Symfony\Component\Messenger\Transport\TransportInterface|null
     */
    private $doctrineReceiver;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-refund');

        $this->doctrineReceiver = self::$container->get('messenger.transport.process_refunds');

        $this->stripeRefundRepository = self::$container->get('doctrine')->getRepository(StripeRefund::class);
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $stripeRefundsPending = $this->stripeRefundRepository->findBy([
            'status' => StripeRefund::REFUND_PENDING,
        ]);

        // PA12 returns 2 new refunds
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertCount(4, $this->doctrineReceiver->getSent());
        $this->assertCount(12, $stripeRefundsPending);

        // test commission for reversal
        $message = $this->doctrineReceiver->getSent()[0]->getMessage();

        $this->assertEquals('6666', $message->geMiraklRefundId());

        $stripeRefund = $this->stripeRefundRepository->findOneBy([
            'miraklRefundId' => '6666',
        ]);

        $this->assertEquals('Order_Amount_Breakdown_0002-A-1', $stripeRefund->getMiraklOrderLineId());
        $this->assertEquals(StripeRefund::REFUND_PENDING, $stripeRefund->getStatus());

        $message = $this->doctrineReceiver->getSent()[1]->getMessage();

        $this->assertEquals('1199', $message->geMiraklRefundId());

        // test with decimal amount for commission & amount
        $message = $this->doctrineReceiver->getSent()[2]->getMessage();

        $this->assertEquals('4242', $message->geMiraklRefundId());

        $stripeRefundsDecimal = $this->stripeRefundRepository->findOneBy([
            'miraklRefundId' => '4242',
        ]);

        $this->assertEquals(1999, $stripeRefundsDecimal->getAmount());

        // test retry on failed refund
        $message = $this->doctrineReceiver->getSent()[3]->getMessage();

        $this->assertEquals('1111', $message->geMiraklRefundId());

        $stripeRefund = $this->stripeRefundRepository->findOneBy([
            'miraklRefundId' => '1111',
        ]);

        $this->assertEquals(StripeRefund::REFUND_PENDING, $stripeRefund->getStatus());
    }
}
