<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UniqueConstraint;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/transfers"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/transfers/{id}", "requirements"={"id"="\d+"}},
 *      }
 * )
 * @ORM\Table(
 *    uniqueConstraints={
 *        @UniqueConstraint(name="transfer",
 *            columns={"type", "mirakl_id"})
 *    }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\StripeTransferRepository")
 */
class StripeTransfer
{
    //Transfer status
    public const TRANSFER_PENDING = 'TRANSFER_PENDING';
    public const TRANSFER_INVALID_AMOUNT = 'TRANSFER_INVALID_AMOUNT';
    public const TRANSFER_CREATED = 'TRANSFER_CREATED';
    public const TRANSFER_FAILED = 'TRANSFER_FAILED';

    //Transfer types
    public const TRANSFER_ORDER = 'TRANSFER_ORDER';
    public const TRANSFER_SUBSCRIPTION = 'TRANSFER_SUBSCRIPTION';
    public const TRANSFER_EXTRA_CREDITS = 'TRANSFER_EXTRA_CREDITS';
    public const TRANSFER_EXTRA_INVOICES = 'TRANSFER_EXTRA_INVOICES';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(name="mirakl_id", type="string")
     */
    private $miraklId;

    /**
     * @ORM\Column(name="type", type="string")
     */
    private $type;

    /**
     * @ORM\ManyToOne(targetEntity="MiraklStripeMapping")
     */
    private $miraklStripeMapping;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $transferId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $transactionId;

    /**
     * @ORM\Column(type="integer")
     */
    private $amount;

    /**
     * @ORM\Column(type="string")
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $failedReason;

    /**
     * @ORM\Column(type="string")
     */
    private $currency;

    /**
     * @ORM\Column(type="datetime")
     */
    private $miraklUpdateTime;

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
            self::TRANSFER_PENDING,
            self::TRANSFER_INVALID_AMOUNT,
            self::TRANSFER_CREATED,
            self::TRANSFER_FAILED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::TRANSFER_INVALID_AMOUNT,
            self::TRANSFER_FAILED,
        ];
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::TRANSFER_ORDER,
            self::TRANSFER_SUBSCRIPTION,
            self::TRANSFER_EXTRA_CREDITS,
            self::TRANSFER_EXTRA_INVOICES,
        ];
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMiraklId(): ?string
    {
        return $this->miraklId;
    }

    public function setMiraklId(string $miraklId): self
    {
        $this->miraklId = $miraklId;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!in_array($type, self::getAvailableTypes())) {
            throw new \InvalidArgumentException('Invalid transfer type');
        }
        $this->type = $type;

        return $this;
    }

    public function getMiraklStripeMapping(): ?MiraklStripeMapping
    {
        return $this->miraklStripeMapping;
    }

    public function setMiraklStripeMapping(MiraklStripeMapping $miraklStripeMapping): self
    {
        $this->miraklStripeMapping = $miraklStripeMapping;

        return $this;
    }

    public function getTransferId(): ?string
    {
        return $this->transferId;
    }

    public function setTransferId(string $transferId): self
    {
        $this->transferId = $transferId;

        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): self
    {
        $this->transactionId = $transactionId;

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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::getAvailableStatus())) {
            throw new \InvalidArgumentException('Invalid order status');
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
