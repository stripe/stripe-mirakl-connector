<?php

namespace App\Service;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\MiraklClient;

class ConfigService
{
    public const PROCESS_TRANSFER_CHECKPOINT = 'process_transfer_checkpoint';

    /**
     * @var ConfigRepository
     */
    private $configRepository;

    public function __construct(ConfigRepository $configRepository)
    {
        $this->configRepository = $configRepository;
    }

    /**
     * @return Config
     */
    private function getConfigByKey(string $key): Config
    {
        $config = $this->configRepository->findByKey($key);

        if (null === $config) {
            $config = new Config();
            $config->setKey($key);
            $this->configRepository->persist($config);
        }

        return $config;
    }

    /**
     * @return self
     */
    private function save(): self
    {
        $this->configRepository->flush();
        return $this;
    }

    /**
     * @return string|null
     */
    public function getProcessTransferCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(self::PROCESS_TRANSFER_CHECKPOINT);
        return $config->getValue();
    }

    /**
     * @param string|null $value
     * @return self
     */
    public function setProcessTransferCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(self::PROCESS_TRANSFER_CHECKPOINT);
        $config->setValue($value);
        return $this->save();
    }
}
