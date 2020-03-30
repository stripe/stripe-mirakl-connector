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
 * @ORM\Entity(repositoryClass="App\Repository\MiraklRefundRepository")
 */
class MiraklRefund
{
    public const REFUND_PENDING = 'REFUND_PENDING';
    public const REFUND_CREATED = 'REFUND_CREATED';
    public const REFUND_FAILED = 'REFUND_FAILED';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="integer")
     */
    private $amount;

    /**
     * @ORM\Column(type="string")
     */
    private $currency;

    /**
     * @ORM\Column(type="string", unique=true)
     */
    private $miraklRefundId;

    /**
     * @ORM\Column(type="string")
     */
    private $miraklOrderId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $stripeRefundId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $stripeReversalId;

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
            self::REFUND_PENDING,
            self::REFUND_CREATED,
            self::REFUND_FAILED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::REFUND_FAILED,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getMiraklRefundId(): ?string
    {
        return $this->miraklRefundId;
    }

    public function setMiraklRefundId(string $miraklRefundId): self
    {
        $this->miraklRefundId = $miraklRefundId;

        return $this;
    }

    public function getMiraklOrderId(): ?string
    {
        return $this->miraklOrderId;
    }

    public function setMiraklOrderId(string $miraklOrderId): self
    {
        $this->miraklOrderId = $miraklOrderId;

        return $this;
    }

    public function getStripeRefundId(): ?string
    {
        return $this->stripeRefundId;
    }

    public function setStripeRefundId(string $stripeRefundId): self
    {
        $this->stripeRefundId = $stripeRefundId;

        return $this;
    }

    public function getStripeReversalId(): ?string
    {
        return $this->stripeReversalId;
    }

    public function setStripeReversalId(string $stripeReversalId): self
    {
        $this->stripeReversalId = $stripeReversalId;

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
