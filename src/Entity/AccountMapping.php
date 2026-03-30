<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Gedmo\Mapping\Annotation\Timestampable;

#[ApiResource(
    operations: [
        new GetCollection(uriTemplate: '/mappings'),
        new Get(uriTemplate: '/mappings/{id}', requirements: ['id' => '\d+']),
        new Delete(uriTemplate: '/mappings/{id}', requirements: ['id' => '\d+'])
    ]
)]
#[Entity(repositoryClass: 'App\Repository\AccountMappingRepository')]
class AccountMapping
{
    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private int $id;

    #[Column(type: 'integer', unique: true)]
    private int $miraklShopId;

    #[Column(type: 'string', length: 255, unique: true)]
    private string $stripeAccountId;

    #[Column(type: 'string', length: 255, nullable: true)]
    private ?string $onboardingToken = null;

    #[Column(type: 'boolean', options: ['default' => false])]
    private bool $payoutEnabled = false;

    #[Column(type: 'boolean', options: ['default' => false])]
    private bool $payinEnabled = false;

    #[Column(type: 'boolean', options: ['default' => false])]
    private bool $ignored = false;

    #[Column(type: 'string', length: 255, nullable: true)]
    private ?string $disabledReason;

    #[Column(type: 'datetime', options: ['default' => new CurrentTimestamp()])]
    #[Timestampable(on: 'create')]
    private \DateTimeInterface $creationDatetime;

    #[Column(type: 'datetime', options: ['default' => new CurrentTimestamp()])]
    #[Timestampable(on: 'update')]
    private \DateTimeInterface $modificationDatetime;

    public function getId(): int
    {
        return $this->id;
    }

    public function getMiraklShopId(): int
    {
        return $this->miraklShopId;
    }

    public function setMiraklShopId(int $miraklShopId): self
    {
        $this->miraklShopId = $miraklShopId;

        return $this;
    }

    public function getStripeAccountId(): string
    {
        return $this->stripeAccountId;
    }

    public function setStripeAccountId(string $stripeAccountId): self
    {
        $this->stripeAccountId = $stripeAccountId;

        return $this;
    }

    public function getOnboardingToken(): ?string
    {
        return $this->onboardingToken;
    }

    public function setOnboardingToken(?string $onboardingToken): self
    {
        $this->onboardingToken = $onboardingToken;

        return $this;
    }

    public function getPayoutEnabled(): ?bool
    {
        return $this->payoutEnabled;
    }

    public function setPayoutEnabled(bool $payoutEnabled): self
    {
        $this->payoutEnabled = $payoutEnabled;

        return $this;
    }

    public function getPayinEnabled(): ?bool
    {
        return $this->payinEnabled;
    }

    public function setPayinEnabled(bool $payinEnabled): self
    {
        $this->payinEnabled = $payinEnabled;

        return $this;
    }

    public function getIgnored(): ?bool
    {
        return $this->ignored;
    }

    public function setIgnored(bool $ignored): self
    {
        $this->ignored = $ignored;

        return $this;
    }

    public function getDisabledReason(): ?string
    {
        return $this->disabledReason;
    }

    public function setDisabledReason(?string $disabledReason): self
    {
        $this->disabledReason = $disabledReason;

        return $this;
    }

    public function getCreationDatetime(): ?\DateTimeInterface
    {
        return $this->creationDatetime;
    }

    public function setCreationDatetime(\DateTimeInterface $creationDatetime): self
    {
        $this->creationDatetime = $creationDatetime;

        return $this;
    }

    public function getModificationDatetime(): ?\DateTimeInterface
    {
        return $this->modificationDatetime;
    }

    public function setModificationDatetime(\DateTimeInterface $modificationDatetime): self
    {
        $this->modificationDatetime = $modificationDatetime;

        return $this;
    }
}
