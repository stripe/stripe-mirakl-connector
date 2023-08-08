<?php

namespace App\Validator;

use App\Service\MiraklClient;
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

    public function validate($miraklShopId, Constraint $constraint): bool
    {
        if ($constraint instanceof MiraklShopId) {
            $miraklShop = $this->miraklClient->listShopsByIds([$miraklShopId]);
            if (1 !== count($miraklShop)) {
                $this->context->buildViolation($constraint->message)->addViolation();

                return false;
            }
        }

        return true;
    }
}
