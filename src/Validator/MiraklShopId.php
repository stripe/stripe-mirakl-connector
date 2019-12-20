<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * @Annotation
 * @Target({"PROPERTY"})
 */
class MiraklShopId extends Constraint
{
    public $message = 'The Mirakl shop ID "{{ miraklShopId }}" is not valid.';
}
