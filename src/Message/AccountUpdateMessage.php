<?php

namespace App\Message;

class AccountUpdateMessage implements NotifiableMessageInterface
{
    /**
     * @var array
     */
    private $content;

    private static function getType(): string
    {
        return 'account.updated';
    }

    public function __construct(int $miraklShopId, string $stripeUserId)
    {
        $this->content = [
            'type' => self::getType(),
            'payload' => [
                'miraklShopId' => $miraklShopId,
                'stripeUserId' => $stripeUserId,
            ],
        ];
    }

    public function getContent(): array
    {
        return $this->content;
    }
}
