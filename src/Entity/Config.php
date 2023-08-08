<?php

namespace App\Entity;

use App\Exception\InvalidArgumentException;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity(repositoryClass="App\Repository\ConfigRepository")
 */
class Config
{
    public const PRODUCT_PAYMENT_SPLIT_CHECKPOINT = 'product_payment_split_checkpoint';
    public const SERVICE_PAYMENT_SPLIT_CHECKPOINT = 'service_payment_split_checkpoint';
    public const SELLER_SETTLEMENT_CHECKPOINT = 'seller_settlement_checkpoint';
    public const SELLER_ONBOARDING_CHECKPOINT = 'seller_onboarding_checkpoint';

    /**
     * @ORM\Id()
     *
     * @ORM\GeneratedValue()
     *
     * @ORM\Column(type="integer")
     */
    private int $id;

    /**
     * @ORM\Column(name="`key`", type="string", unique=true)
     */
    private string $key;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private ?string $value = null;

    public static function getAvailableKeys(): array
    {
        return [
            self::PRODUCT_PAYMENT_SPLIT_CHECKPOINT,
            self::SERVICE_PAYMENT_SPLIT_CHECKPOINT,
            self::SELLER_SETTLEMENT_CHECKPOINT,
            self::SELLER_ONBOARDING_CHECKPOINT,
        ];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        if (!in_array($key, self::getAvailableKeys())) {
            throw new InvalidArgumentException('Invalid config key');
        }

        $this->key = $key;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }
}
