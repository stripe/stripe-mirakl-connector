<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * @ORM\Entity(repositoryClass="App\Repository\StripePaymentRepository")
 */
class StripePayment
{
    public const PAYMENT_SUCCEEDED = 'SUCCEEDED';
    public const PAYMENT_TO_CAPTURE = 'TO_CAPTURE';

    /**
     * @ORM\Id()
     * @ORM\GeneratedValue()
     * @ORM\Column(type="integer")
     */
    private $id;

    /**
     * @ORM\Column(type="string")
     */
    private $miraklOrderId;

    /**
     * @ORM\Column(type="string")
     */
    private $stripePaymentId;

    /**
     * @ORM\Column(type="string")
     */
    private $status;

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
     * @return string[]
     */
    public static function getAvailableStatus(): array
    {
        return [
            self::PAYMENT_SUCCEEDED,
            self::PAYMENT_TO_CAPTURE,
        ];
    }

    /**
     * @return mixed
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return mixed
     */
    public function getMiraklOrderId()
    {
        return $this->miraklOrderId;
    }

    /**
     * @param mixed $miraklOrderId
     * @return self
     */
    public function setMiraklOrderId($miraklOrderId): self
    {
        $this->miraklOrderId = $miraklOrderId;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getStripePaymentId()
    {
        return $this->stripePaymentId;
    }

    /**
     * @param mixed $stripePaymentId
     * @return self
     */
    public function setStripePaymentId($stripePaymentId): self
    {
        $this->stripePaymentId = $stripePaymentId;
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
     * @param $status
     * @return self
     */
    public function setStatus($status): self
    {
        if (!in_array($status, self::getAvailableStatus(), true)) {
            throw new \InvalidArgumentException('Invalid payment status');
        }

        $this->status = $status;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getCreationDatetime()
    {
        return $this->creationDatetime;
    }

    /**
     * @param mixed $creationDatetime
     * @return self
     */
    public function setCreationDatetime($creationDatetime): self
    {
        $this->creationDatetime = $creationDatetime;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getModificationDatetime()
    {
        return $this->modificationDatetime;
    }

    /**
     * @param mixed $modificationDatetime
     * @return self
     */
    public function setModificationDatetime($modificationDatetime): self
    {
        $this->modificationDatetime = $modificationDatetime;
        return $this;
    }
}
