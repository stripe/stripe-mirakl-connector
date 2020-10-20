<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/payouts"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/payouts/{id}", "requirements"={"id"="\d+"}},
 *      }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\StripePayoutRepository")
 */
class StripePayout
{
    public const PAYOUT_PENDING = 'PAYOUT_PENDING';
    public const PAYOUT_CREATED = 'PAYOUT_CREATED';
    public const PAYOUT_FAILED = 'PAYOUT_FAILED';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\ManyToOne(targetEntity="AccountMapping")
     */
    private $accountMapping;

    /**
     * @ORM\Column(type="integer")
     */
    private $amount = 0;

    /**
     * @ORM\Column(type="string")
     */
    private $currency;

    /**
     * @ORM\Column(type="datetime")
     */
    private $miraklUpdateTime;

    /**
     * @ORM\Column(type="integer", unique=true)
     */
    private $miraklInvoiceId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $stripePayoutId;

    /**
     * @ORM\Column(type="string")
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $failedReason;

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

    public static function getAvailableStatus(): array
    {
        return [
            self::PAYOUT_PENDING,
            self::PAYOUT_CREATED,
            self::PAYOUT_FAILED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::PAYOUT_FAILED,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountMapping(): ?AccountMapping
    {
        return $this->accountMapping;
    }

    public function setAccountMapping(AccountMapping $accountMapping): self
    {
        $this->accountMapping = $accountMapping;

        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getMiraklUpdateTime(): ?\DateTimeInterface
    {
        return $this->miraklUpdateTime;
    }

    public function setMiraklUpdateTime(\DateTime $miraklUpdateTime): self
    {
        $this->miraklUpdateTime = $miraklUpdateTime;

        return $this;
    }

    public function getMiraklInvoiceId(): int
    {
        return $this->miraklInvoiceId;
    }

    public function setMiraklInvoiceId(int $miraklInvoiceId): self
    {
        $this->miraklInvoiceId = $miraklInvoiceId;

        return $this;
    }

    public function getStripePayoutId(): ?string
    {
        return $this->stripePayoutId;
    }

    public function setStripePayoutId(string $stripePayoutId): self
    {
        $this->stripePayoutId = $stripePayoutId;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::getAvailableStatus())) {
            throw new \InvalidArgumentException('Invalid payout status');
        }
        $this->status = $status;

        return $this;
    }

    public function getFailedReason(): ?string
    {
        return $this->failedReason;
    }

    public function setFailedReason(?string $failedReason): self
    {
        $this->failedReason = $failedReason;

        return $this;
    }

    public function getCreationDatetime(): ?\DateTimeInterface
    {
        return $this->creationDatetime;
    }

    public function setCreationDatetime(\DateTime $creationDatetime): self
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
