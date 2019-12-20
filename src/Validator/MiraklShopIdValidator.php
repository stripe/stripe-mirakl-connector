<?php

namespace App\Validator;

use App\Utils\MiraklClient;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class MiraklShopIdValidator extends ConstraintValidator
{
    /**
     * @var MiraklClient
     */
    private $miraklClient;

    public function __construct(MiraklClient $miraklClient)
    {
        $this->miraklClient = $miraklClient;
    }

    public function validate($miraklShopId, Constraint $constraint)
    {
        if ($constraint instanceof \App\Validator\MiraklShopId) {
            $miraklShop = $this->miraklClient->fetchShops([$miraklShopId]);
            if (1 === count($miraklShop)) {
                return true;
            }
            /* @var $constraint \App\Validator\MiraklShopId */
            $this->context->buildViolation($constraint->message)
                ->addViolation();

            return false;
        }
    }
}
