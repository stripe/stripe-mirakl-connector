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
        $this->assertCount(2, $this->doctrineReceiver->getSent());
        $this->assertCount(10, $stripeRefundsPending);
    }
}
