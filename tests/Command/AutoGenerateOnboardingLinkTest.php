<?php

namespace App\Tests\Command;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @group integration
 */
class AutoGenerateOnboardingLinkTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $container = self::$container;

        ClockMock::register(__CLASS__);
        $this->command = $application->find('connector:sync:onboarding');
    }

    public function testEmptyExecute()
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testExecute()
    {
        ClockMock::withClockMock(date_create('2019-10-01 00:05 UTC')->getTimestamp());
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([
            'command' => $this->command->getName(),
            'delay' => '5',
        ]);

        $this->assertEquals(0, $commandTester->getStatusCode());
        ClockMock::withClockMock(false);
    }
}
