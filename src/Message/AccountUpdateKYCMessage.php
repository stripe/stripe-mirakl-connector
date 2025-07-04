<?php

namespace App\Message;

class AccountUpdateKYCMessage implements NotifiableMessageInterface
{
    /**
     * @var array
     */
    private $content;

    private static function getType(): string
    {
        return 'account.updated';
    }

    public function __construct(int $miraklShopId, $stripeAccount)
    {
        $this->content = [
            'type' => self::getType(),
            'payload' => [
                'miraklShopId' => $miraklShopId,
                'stripeAccount' => $stripeAccount,
            ],
        ];
    }

    public function getContent(): array
    {
        return $this->content;
    }
}
