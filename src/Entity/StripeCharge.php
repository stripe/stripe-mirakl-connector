<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity(repositoryClass="App\Repository\StripeChargeRepository")
 */
class StripeCharge
{
    public const TO_CAPTURE = 'to_capture';
    public const CAPTURED = 'captured';
    public const CANCELED = 'canceled';

    public const ALLOWED_STATUS = [
        self::TO_CAPTURE,
        self::CAPTURED,
        self::CANCELED,
    ];

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $miraklOrderId;

    /**
     * @ORM\Column(type="string", unique=true)
     */
    private $stripeChargeId;

    /**
     * @ORM\Column(type="string")
     */
    private $status = self::TO_CAPTURE;

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

    /**
     * @return int
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @return string|null
     */
    public function getMiraklOrderId(): ?string
    {
        return $this->miraklOrderId;
    }

    /**
     * @param string|null $miraklOrderId
     * @return self
     */
    public function setMiraklOrderId(?string $miraklOrderId): self
    {
        $this->miraklOrderId = $miraklOrderId;
        return $this;
    }

    /**
     * @return string
     */
    public function getStripeChargeId(): string
    {
        return $this->stripeChargeId;
    }

    /**
     * @param string $stripeChargeId
     * @return self
     */
    public function setStripeChargeId(string $stripeChargeId): self
    {
        $this->stripeChargeId = $stripeChargeId;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @param string $status
     * @return self
     */
    public function setStatus($status): self
    {
        if (!in_array($status, self::ALLOWED_STATUS, true)) {
            throw new \InvalidArgumentException('Invalid payment status');
        }

        $this->status = $status;
        return $this;
    }

    /**
     * @return self
     */
    public function capture()
    {
        return $this->setStatus(self::CAPTURED);
    }

    /**
     * @return self
     */
    public function cancel()
    {
        return $this->setStatus(self::CANCELED);
    }

    /**
     * @return mixed
     */
    public function getCreationDatetime()
    {
        return $this->creationDatetime;
    }

    /**
     * @param \DateTimeInterface $creationDatetime
     * @return self
     */
    public function setCreationDatetime(\DateTimeInterface $creationDatetime): self
    {
        $this->creationDatetime = $creationDatetime;
        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getModificationDatetime(): ?\DateTimeInterface
    {
        return $this->modificationDatetime;
    }

    /**
     * @param \DateTimeInterface $modificationDatetime
     * @return self
     */
    public function setModificationDatetime(\DateTimeInterface $modificationDatetime): self
    {
        $this->modificationDatetime = $modificationDatetime;
        return $this;
    }
}
