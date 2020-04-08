<?php

namespace App\Tests\Command;

use App\Entity\MiraklRefund;
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
     * @var MiraklRefundRepository
     */
    private $miraklRefundRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $application = new Application($kernel);
        $this->command = $application->find('connector:dispatch:process-refund');

        $this->doctrineReceiver = self::$container->get('messenger.transport.process_refunds');

        $this->miraklRefundRepository = self::$container->get('doctrine')->getRepository(MiraklRefund::class);
    }

    public function testNominalExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
        ]);

        $miraklRefundsPending = $this->miraklRefundRepository->findBy([
            'status' => MiraklRefund::REFUND_PENDING,
        ]);

        // PA12 returns 2 new refunds
        $this->assertEquals(0, $commandTester->getStatusCode());
        $this->assertEquals(2, $this->doctrineReceiver->getMessageCount());
        $this->assertEquals(7, count($miraklRefundsPending));
    }
}
