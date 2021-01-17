<?php

namespace App\Tests\Command;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;


class ConfigServiceTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    /**
     * @var ConfigService
     */
    private $configService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->configRepository = self::$container->get('doctrine')->getRepository(Config::class);
        $this->configService = new ConfigService($this->configRepository);
    }

    public function testGetValue()
    {
        $val = $this->configService->getProductPaymentSplitCheckpoint();
        $this->assertEquals(null, $val);
    }

    public function testUpdateValue()
    {
        $this->configService->setProductPaymentSplitCheckpoint('2000-01-01');
        $val = $this->configService->getProductPaymentSplitCheckpoint();
        $this->assertEquals('2000-01-01', $val);

        $this->configService->setProductPaymentSplitCheckpoint('2001-01-01');
        $val = $this->configService->getProductPaymentSplitCheckpoint();
        $this->assertEquals('2001-01-01', $val);
    }
}
