<?php

namespace App\Repository;

use App\Utils\StripeProxy;
use Stripe\Account;
use Stripe\StripeObject;

class StripeAccountRepository
{
    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    public function __construct(StripeProxy $stripeProxy)
    {
        $this->stripeProxy = $stripeProxy;
    }

    public function setManualPayout($stripeUserId): Account
    {
        return $this->stripeProxy->updateAccount($stripeUserId, ['settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]]]);
    }

    public function findByCode(string $code): StripeObject
    {
        return $this->stripeProxy->loginWithCode($code);
    }
}
