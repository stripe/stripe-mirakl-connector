<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 *
 * @Target({"PROPERTY"})
 */
class MiraklShopId extends Constraint
{
    public string $message = 'The Mirakl shop ID "{{ miraklShopId }}" is not valid.';
}
