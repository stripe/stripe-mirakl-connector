<?php

namespace App\Handler;

use App\Message\AccountUpdateMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;

class UpdateAccountLoginLinkHandler implements MessageHandlerInterface, MessageSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var string
     */
    private $customFieldCode;

    public function __construct(MiraklClient $miraklClient, StripeClient $stripeClient, string $customFieldCode)
    {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->customFieldCode = $customFieldCode;
    }

    public function __invoke(AccountUpdateMessage $message)
    {
        $messagePayload = $message->getContent()['payload'];
        $this->logger->info('Received Stripe `account.updated` webhook. Updating login link.', $messagePayload);

        $loginLink = $this->stripeClient->createLoginLink($messagePayload['stripeUserId']);
        $this->miraklClient->updateShopCustomField($messagePayload['miraklShopId'], $this->customFieldCode, $loginLink);
    }

    public static function getHandledMessages(): iterable
    {
        yield AccountUpdateMessage::class => [
            'from_transport' => 'update_login_link',
        ];
    }
}
