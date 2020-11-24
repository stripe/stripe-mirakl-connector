<?php

namespace App\Handler;

use App\Factory\MiraklPatchShopFactory;
use App\Message\AccountUpdateMessage;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
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
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var MiraklPatchShopFactory
     */
    private $patchFactory;

    public function __construct(MiraklClient $miraklClient, StripeProxy $stripeProxy, MiraklPatchShopFactory $patchFactory)
    {
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->patchFactory = $patchFactory;
    }

    public function __invoke(AccountUpdateMessage $message)
    {
        $messagePayload = $message->getContent()['payload'];
        $this->logger->info('Received Stripe `account.updated` webhook. Updating login link.', $messagePayload);

        $stripeLoginLink = $this->stripeProxy->accountCreateLoginLink($messagePayload['stripeUserId']);

        $shopPatch = $this->patchFactory
            ->setMiraklShopId($messagePayload['miraklShopId'])
            ->setStripeUrl($stripeLoginLink['url'])
            ->buildPatch();
        $this->miraklClient->patchShops([$shopPatch]);
    }

    public static function getHandledMessages(): iterable
    {
        yield AccountUpdateMessage::class => [
            'from_transport' => 'update_login_link',
        ];
    }
}
