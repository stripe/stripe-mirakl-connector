<?php

namespace App\Factory;

class MiraklPatchShopFactory
{
    /**
     * @var string
     */
    private $customFieldCode;

    /**
     * @var int
     */
    private $miraklShopId;

    /**
     * @var string
     */
    private $stripeUrl;

    public function __construct(string $customFieldCode)
    {
        $this->customFieldCode = $customFieldCode;
    }

    public function setMiraklShopId(int $miraklShopId): self
    {
        $this->miraklShopId = $miraklShopId;

        return $this;
    }

    public function setStripeUrl(string $stripeUrl): self
    {
        $this->stripeUrl = $stripeUrl;

        return $this;
    }

    public function buildPatch()
    {
        return [
            'shop_id' => $this->miraklShopId,
            'shop_additional_fields' => [
                [
                    'code' => $this->customFieldCode,
                    'value' => $this->stripeUrl,
                ],
            ],
        ];
    }
}
