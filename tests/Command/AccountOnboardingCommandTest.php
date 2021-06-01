<?php

namespace App\Tests\Command;

use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bridge\PhpUnit\ClockMock;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AccountOnboardingCommandTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    protected $command;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $container = self::$container;

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
}
