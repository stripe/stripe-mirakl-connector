<?php

namespace App\Entity;

class MiraklShop
{
    protected $shop;

    public function __construct(array $shop)
    {
        $this->shop = $shop;
    }

    public function getShop(): array
    {
        return $this->shop;
    }

    public function getId(): int
    {
        return (int) $this->shop['shop_id'];
    }

    public function getLastUpdatedDate(): string
    {
        return $this->shop['last_updated_date'];
    }

    public function getCustomFieldValue($code): ?string
    {
        foreach ($this->shop['shop_additional_fields'] as $field) {
            if ($field['code'] === $code) {
                return $field['value'];
            }
        }

        return null;
    }
}
