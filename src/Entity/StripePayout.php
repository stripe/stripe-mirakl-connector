<?php

namespace App\Entity;

use ApiPlatform\Core\Annotation\ApiResource;
use Doctrine\DBAL\Schema\DefaultExpression\CurrentTimestamp;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\ManyToOne;
use Gedmo\Mapping\Annotation\Timestampable;

#[ApiResource(
    collectionOperations: [
        'get' => ['path' => '/payouts']
    ],
    itemOperations: [
        'get' => ['path' => '/payouts/{id}', 'requirements' => ['id' => '\d+']]
    ]
)]
#[Entity(repositoryClass: 'App\Repository\StripePayoutRepository')]
class StripePayout
{
    public const PAYOUT_ON_HOLD = 'PAYOUT_ON_HOLD';
    public const PAYOUT_ABORTED = 'PAYOUT_ABORTED';
    public const PAYOUT_PENDING = 'PAYOUT_PENDING';
    public const PAYOUT_FAILED = 'PAYOUT_FAILED';
    public const PAYOUT_CREATED = 'PAYOUT_CREATED';

    // Payout status reasons: on hold
    public const PAYOUT_STATUS_REASON_SHOP_NOT_READY = 'Cannot find Stripe account for shop ID %s';
    public const PAYOUT_STATUS_REASON_SHOP_PAYOUT_DISABLED = 'Payouts are disabled shop ID %s';

    // Payout status reasons: aborted
    public const PAYOUT_STATUS_REASON_INVALID_AMOUNT = 'Amount must be positive, input was: %d';
    public const PAYOUT_STATUS_REASON_NO_SHOP_ID = 'No shop ID provided';

    #[Id]
    #[GeneratedValue]
    #[Column(type: 'integer')]
    private int $id;

    #[ManyToOne(targetEntity: 'AccountMapping')]
    private ?AccountMapping $accountMapping;

    #[Column(type: 'integer', nullable: true)]
    private ?int $amount = 0;

    #[Column(type: 'string', nullable: true)]
    private ?string $currency;

    #[Column(type: 'integer', unique: true)]
    private int $miraklInvoiceId;

    #[Column(type: 'string', nullable: true)]
    private ?string $payoutId = null;

    #[Column(type: 'string')]
    private string $status;

    #[Column(type: 'string', length: 1024, nullable: true)]
    private ?string $statusReason = null;

    #[Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $miraklCreatedDate;

    #[Column(type: 'datetime', options: ['default' => new CurrentTimestamp()])]
    #[Timestampable(on: 'create')]
    private \DateTimeInterface $creationDatetime;

    #[Column(type: 'datetime', options: ['default' => new CurrentTimestamp()])]
    #[Timestampable(on: 'update')]
    private \DateTimeInterface $modificationDatetime;

    public static function getAvailableStatus(): array
    {
        return [
            self::PAYOUT_ON_HOLD,
            self::PAYOUT_ABORTED,
            self::PAYOUT_PENDING,
            self::PAYOUT_FAILED,
            self::PAYOUT_CREATED,
        ];
    }

    public static function getInvalidStatus(): array
    {
        return [
            self::PAYOUT_FAILED,
        ];
    }

    public static function getRetriableStatus(): array
    {
        return [
            self::PAYOUT_FAILED,
            self::PAYOUT_ON_HOLD,
        ];
    }

    public function isRetriable(): bool
    {
        return in_array($this->getStatus(), self::getRetriableStatus());
    }

    public function isDispatchable(): bool
    {
        return self::PAYOUT_PENDING === $this->getStatus();
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

    public function getAmount(): ?int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

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

    public function getPayoutId(): ?string
    {
        return $this->payoutId;
    }

    public function setPayoutId(?string $payoutId): self
    {
        $this->payoutId = $payoutId;

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

    public function getStatusReason(): ?string
    {
        return $this->statusReason;
    }

    public function setStatusReason(?string $statusReason): self
    {
        $this->statusReason = $statusReason;

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
