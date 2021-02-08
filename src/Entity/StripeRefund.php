<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use App\Exception\InvalidArgumentException;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ApiResource(
 *      collectionOperations={
 *          "get"={"path"="/refunds"}
 *      },
 *      itemOperations={
 *          "get"={"path"="/refunds/{id}", "requirements"={"id"="\d+"}},
 *      }
 * )
 * @ORM\Entity(repositoryClass="App\Repository\StripeRefundRepository")
 */
class StripeRefund
{
    public const REFUND_ON_HOLD = 'REFUND_ON_HOLD';
    public const REFUND_ABORTED = 'REFUND_ABORTED';
    public const REFUND_PENDING = 'REFUND_PENDING';
    public const REFUND_FAILED = 'REFUND_FAILED';
    public const REFUND_CREATED = 'REFUND_CREATED';

    // Refund status reasons: on hold
    public const REFUND_STATUS_REASON_NO_CHARGE_ID = 'Cannot find the ID of the payment to be refunded';
    public const REFUND_STATUS_REASON_PAYMENT_NOT_READY = 'Payment %s is not ready yet, status is %s';
    public const REFUND_STATUS_REASON_REFUND_NOT_FOUND = 'Cannot find StripeRefund with ID %s';
    public const REFUND_STATUS_REASON_REFUND_NOT_VALIDATED = 'Refund %s has yet to be validated';

    // Refund status reasons: aborted
    public const REFUND_STATUS_REASON_PAYMENT_FAILED = 'Payment %s failed';
    public const REFUND_STATUS_REASON_PAYMENT_CANCELED = 'Payment %s has been canceled';
    public const REFUND_STATUS_REASON_PAYMENT_REFUNDED = 'Payment %s has been fully refunded';

    // Refund types
    public const REFUND_PRODUCT_ORDER = 'REFUND_PRODUCT_ORDER';
    public const REFUND_SERVICE_ORDER = 'REFUND_SERVICE_ORDER';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(name="type", type="string")
     */
    private $type;

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
    private $miraklOrderLineId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $transactionId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $stripeRefundId;

    /**
     * @ORM\Column(type="string")
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $statusReason;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $miraklValidationTime;

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
            self::REFUND_ON_HOLD,
            self::REFUND_ABORTED,
            self::REFUND_PENDING,
            self::REFUND_FAILED,
            self::REFUND_CREATED
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::REFUND_FAILED
        ];
    }

    public static function getRetriableStatus(): array
    {
        return [
            self::REFUND_FAILED,
            self::REFUND_ON_HOLD
        ];
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::REFUND_PRODUCT_ORDER,
            self::REFUND_SERVICE_ORDER
        ];
    }

    public function isRetriable(): bool
    {
        return in_array($this->getStatus(), self::getRetriableStatus());
    }

    public function isDispatchable(): bool
    {
        return self::REFUND_PENDING === $this->getStatus();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!in_array($type, self::getAvailableTypes())) {
            throw new InvalidArgumentException('Invalid refund type');
        }
        $this->type = $type;

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

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(string $transactionId): self
    {
        $this->transactionId = $transactionId;

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

    /**
     * @return mixed
     */
    public function getMiraklOrderLineId()
    {
        return $this->miraklOrderLineId;
    }

    /**
     * @param mixed $miraklOrderLineId
     * @return StripeRefund
     */
    public function setMiraklOrderLineId($miraklOrderLineId)
    {
        $this->miraklOrderLineId = $miraklOrderLineId;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        if (!in_array($status, self::getAvailableStatus())) {
            throw new \InvalidArgumentException('Invalid refund status');
        }
        $this->status = $status;

        return $this;
    }

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function setStatusReason(?string $statusReason): self
    {
        $this->statusReason = $statusReason;

        return $this;
    }

    public function getMiraklValidationTime(): ?\DateTimeInterface
    {
        return $this->miraklValidationTime;
    }

    public function setMiraklValidationTime(\DateTime $miraklValidationTime): self
    {
        $this->miraklValidationTime = $miraklValidationTime;

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
