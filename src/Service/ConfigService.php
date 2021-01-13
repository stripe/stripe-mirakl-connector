<?php

namespace App\Service;

use App\Entity\Config;
use App\Repository\ConfigRepository;
use App\Service\MiraklClient;

class ConfigService
{

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
            $this->configRepository->persistAndFlush($config);
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
    public function getPaymentSplitCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::PAYMENT_SPLIT_CHECKPOINT);
        return $config->getValue();
    }

    /**
     * @param string|null $value
     * @return self
     */
    public function setPaymentSplitCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::PAYMENT_SPLIT_CHECKPOINT);
        $config->setValue($value);
        return $this->save();
    }

    /**
     * @return string|null
     */
    public function getSellerSettlementCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::SELLER_SETTLEMENT_CHECKPOINT);
        return $config->getValue();
    }

    /**
     * @param string|null $value
     * @return self
     */
    public function setSellerSettlementCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::SELLER_SETTLEMENT_CHECKPOINT);
        $config->setValue($value);
        return $this->save();
    }
}
