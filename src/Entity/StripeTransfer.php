<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use ApiPlatform\Core\Annotation\ApiFilter;
use ApiPlatform\Core\Bridge\Doctrine\Orm\Filter\SearchFilter;
use App\Exception\InvalidArgumentException;
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
 * @ApiFilter(SearchFilter::class, properties={"miraklId":"exact" })
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
    // Transfer status
    public const TRANSFER_ON_HOLD = 'TRANSFER_ON_HOLD';
    public const TRANSFER_ABORTED = 'TRANSFER_ABORTED';
    public const TRANSFER_PENDING = 'TRANSFER_PENDING';
    public const TRANSFER_FAILED = 'TRANSFER_FAILED';
    public const TRANSFER_CREATED = 'TRANSFER_CREATED';
    public const TRANSFER_IGNORED = 'TRANSFER_IGNORED';

    // Transfer status reasons: on hold
    public const TRANSFER_STATUS_REASON_SHOP_NOT_READY = 'Cannot find Stripe account for shop ID %s';
    public const TRANSFER_STATUS_REASON_ORDER_NOT_READY = 'Order is not ready yet, status is %s';
    public const TRANSFER_STATUS_REASON_PAYMENT_NOT_READY = 'Payment %s is not ready yet, status is %s';
    public const TRANSFER_STATUS_REASON_REFUND_NOT_FOUND = 'Cannot find StripeRefund with ID %s';
    public const TRANSFER_STATUS_REASON_REFUND_NOT_VALIDATED = 'Refund %s has yet to be validated';
    public const TRANSFER_STATUS_REASON_TRANSFER_NOT_READY = 'Payment split has to occur before the transfer can be reversed for a refund';
    public const TRANSFER_STATUS_REASON_ACCOUNT_NOT_FOUND = 'Cannot find Stripe account for ID %s';

    // Transfer status reasons: aborted
    public const TRANSFER_STATUS_REASON_ORDER_ABORTED = 'Order cannot be processed, status is %s';
    public const TRANSFER_STATUS_REASON_INVALID_AMOUNT = 'Amount must be positive, input was: %d';
    public const TRANSFER_STATUS_REASON_PAYMENT_FAILED = 'Payment %s failed';
    public const TRANSFER_STATUS_REASON_PAYMENT_CANCELED = 'Payment %s has been canceled';
    public const TRANSFER_STATUS_REASON_PAYMENT_REFUNDED = 'Payment %s has been fully refunded';
    public const TRANSFER_STATUS_REASON_NO_SHOP_ID = 'No shop ID provided';
    public const TRANSFER_STATUS_REASON_ORDER_REFUND_ABORTED = 'Refund %s has been aborted';
    public const TRANSFER_STATUS_REASON_NO_ACCOUNT_ID = 'No stripe account ID provided';

    // Transfer types
    public const TRANSFER_PRODUCT_ORDER = 'TRANSFER_PRODUCT_ORDER';
    public const TRANSFER_SERVICE_ORDER = 'TRANSFER_SERVICE_ORDER';
    public const TRANSFER_REFUND = 'TRANSFER_REFUND';
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
     * @ORM\ManyToOne(targetEntity="AccountMapping")
     */
    private $accountMapping;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $transferId;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $transactionId;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $amount;

    /**
     * @ORM\Column(type="string")
     */
    private $status;

    /**
     * @ORM\Column(type="string", length=1024, nullable=true)
     */
    private $statusReason;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $currency;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $miraklCreatedDate;

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
            self::TRANSFER_CREATED,
            self::TRANSFER_FAILED,
            self::TRANSFER_ON_HOLD,
            self::TRANSFER_ABORTED,
            self::TRANSFER_IGNORED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::TRANSFER_FAILED,
        ];
    }

    public static function getRetriableStatus(): array
    {
        return [
            self::TRANSFER_FAILED,
            self::TRANSFER_ON_HOLD,
        ];
    }

    public static function getAvailableTypes(): array
    {
        return [
            self::TRANSFER_PRODUCT_ORDER,
            self::TRANSFER_SERVICE_ORDER,
            self::TRANSFER_REFUND,
            self::TRANSFER_SUBSCRIPTION,
            self::TRANSFER_EXTRA_CREDITS,
            self::TRANSFER_EXTRA_INVOICES,
        ];
    }

    public static function getOrderTypes(): array
    {
        return [
            self::TRANSFER_PRODUCT_ORDER,
            self::TRANSFER_SERVICE_ORDER,
        ];
    }

    public static function getInvoiceTypes(): array
    {
        return [
            self::TRANSFER_SUBSCRIPTION,
            self::TRANSFER_EXTRA_CREDITS,
            self::TRANSFER_EXTRA_INVOICES,
        ];
    }

    public function isRetriable(): bool
    {
        return in_array($this->getStatus(), self::getRetriableStatus());
    }

    public function isDispatchable(): bool
    {
        return self::TRANSFER_PENDING === $this->getStatus();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMiraklId(): string
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
            throw new InvalidArgumentException('Invalid transfer type');
        }
        $this->type = $type;

        return $this;
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

    public function getTransferId(): ?string
    {
        return $this->transferId;
    }

    public function setTransferId(?string $transferId): self
    {
        $this->transferId = $transferId;

        return $this;
    }

    public function getTransactionId(): ?string
    {
        return $this->transactionId;
    }

    public function setTransactionId(?string $transactionId): self
    {
        $this->transactionId = $transactionId;

        return $this;
    }

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(?int $amount): self
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
            throw new InvalidArgumentException(
                'Invalid order status. Input was: ' . $status
            );
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

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function getMiraklCreatedDate(): ?\DateTimeInterface
    {
        return $this->miraklCreatedDate;
    }

    public function setMiraklCreatedDate(?\DateTimeInterface $miraklCreatedDate): self
    {
        $this->miraklCreatedDate = $miraklCreatedDate;

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
