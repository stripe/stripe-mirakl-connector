<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity(repositoryClass="App\Repository\StripePaymentRepository")
 */
class StripePayment
{
    public const SUCCEEDED = 'succeeded';
    public const TO_CAPTURE = 'to_capture';
    public const REQUIRES_CAPTURE = 'requires_capture';

    public const ALLOWED_STATUS = [
        self::SUCCEEDED,
        self::TO_CAPTURE,
        self::REQUIRES_CAPTURE
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
     * @ORM\Column(type="string")
     */
    private $stripePaymentId;

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
     * @return int|null
     */
    public function getId(): ?int
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
     * @return string|null
     */
    public function getStripePaymentId(): ?string
    {
        return $this->stripePaymentId;
    }

    /**
     * @param string $stripePaymentId
     * @return self
     */
    public function setStripePaymentId(string $stripePaymentId): self
    {
        $this->stripePaymentId = $stripePaymentId;
        return $this;
    }

    /**
     * @return \DateTimeInterface|null
     */
    public function getCreationDatetime():  ?\DateTimeInterface
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
