<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/mappings"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/mappings/{id}", "requirements"={"id"="\d+"}},
 *          "delete"={"path"="/mappings/{id}", "requirements"={"id"="\d+"}},
 *      }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\AccountMappingRepository")
 */
class AccountMapping
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer", unique=true)
     */
    private $miraklShopId;

    /**
     * @ORM\Column(type="string", length=255, unique=true)
     */
    private $stripeAccountId;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $onboardingToken;

    /**
     * @ORM\Column(type="boolean", options={"default" : false})
     */
    private $payoutEnabled = false;

    /**
     * @ORM\Column(type="boolean", options={"default" : false})
     */
    private $payinEnabled = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $disabledReason;

    /**
     * @ORM\Column(type="datetime", options={"default": "CURRENT_TIMESTAMP"})
     * @Gedmo\Timestampable(on="create")
     */
    private $creationDatetime;

    /**
     * @ORM\Column(type="datetime", options={"default": "CURRENT_TIMESTAMP"})
     * @Gedmo\Timestampable(on="update")
     */
    private $modificationDatetime;

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
