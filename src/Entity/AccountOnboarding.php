<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/onboarding"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/onboarding/{id}", "requirements"={"id"="\d+"}},
 *          "delete"={"path"="/onboarding/{id}", "requirements"={"id"="\d+"}},
 *      }
 * ) * @ORM\Entity(repositoryClass="App\Repository\AccountOnboardingRepository")
 */
class AccountOnboarding
{
    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     */
    private $miraklShopId;

    /**
     * @ORM\Column(type="string", length=48)
     */
    private $stripeState;

    /**
     * @ORM\Column(type="datetime", options={"default": "CURRENT_TIMESTAMP"})
     * @Gedmo\Timestampable(on="create")
     */
    private $creationDatetime;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMiraklShopId(): ?int
    {
        return $this->miraklShopId;
    }

    public function setMiraklShopId(int $miraklShopId): self
    {
        $this->miraklShopId = $miraklShopId;

        return $this;
    }

    public function getStripeState(): ?string
    {
        return $this->stripeState;
    }

    public function setStripeState(string $stripeState): self
    {
        $this->stripeState = $stripeState;

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
}
