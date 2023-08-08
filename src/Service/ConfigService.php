<?php

namespace App\Service;

use App\Entity\Config;
use App\Repository\ConfigRepository;

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

    private function save(): self
    {
        $this->configRepository->flush();

        return $this;
    }

    public function getProductPaymentSplitCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::PRODUCT_PAYMENT_SPLIT_CHECKPOINT);

        return $config->getValue();
    }

    public function setProductPaymentSplitCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::PRODUCT_PAYMENT_SPLIT_CHECKPOINT);
        $config->setValue($value);

        return $this->save();
    }

    public function getServicePaymentSplitCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::SERVICE_PAYMENT_SPLIT_CHECKPOINT);

        return $config->getValue();
    }

    public function setServicePaymentSplitCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::SERVICE_PAYMENT_SPLIT_CHECKPOINT);
        $config->setValue($value);

        return $this->save();
    }

    public function getSellerSettlementCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::SELLER_SETTLEMENT_CHECKPOINT);

        return $config->getValue();
    }

    public function setSellerSettlementCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::SELLER_SETTLEMENT_CHECKPOINT);
        $config->setValue($value);

        return $this->save();
    }

    public function getSellerOnboardingCheckpoint(): ?string
    {
        $config = $this->getConfigByKey(Config::SELLER_ONBOARDING_CHECKPOINT);

        return $config->getValue();
    }

    public function setSellerOnboardingCheckpoint(?string $value): self
    {
        $config = $this->getConfigByKey(Config::SELLER_ONBOARDING_CHECKPOINT);
        $config->setValue($value);

        return $this->save();
    }
}
