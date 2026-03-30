<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class MiraklShopId extends Constraint
{
    public string $message = 'The Mirakl shop ID "{{ miraklShopId }}" is not valid.';
}
