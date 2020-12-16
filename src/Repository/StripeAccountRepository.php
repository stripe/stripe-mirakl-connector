<?php

namespace App\Repository;

use App\Service\StripeClient;
use Stripe\Account;
use Stripe\StripeObject;

class StripeAccountRepository
{
    /**
     * @var StripeClient
     */
    private $stripeClient;

    public function __construct(StripeClient $stripeClient)
    {
        $this->stripeClient = $stripeClient;
    }

    public function setManualPayout($stripeUserId): Account
    {
        return $this->stripeClient->updateAccount($stripeUserId, ['settings' => ['payouts' => ['schedule' => ['interval' => 'manual']]]]);
    }

    public function findByCode(string $code): StripeObject
    {
        return $this->stripeClient->loginWithCode($code);
    }
}
