<?php

namespace App\Handler;

use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Service\SellerOnboardingService;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;

class UpdateAccountLoginLinkHandler implements MessageHandlerInterface, MessageSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    public function __construct(AccountMappingRepository $accountMappingRepository, SellerOnboardingService $sellerOnboardingService)
    {
        $this->accountMappingRepository = $accountMappingRepository;
        $this->sellerOnboardingService = $sellerOnboardingService;
    }

    public function __invoke(AccountUpdateMessage $message)
    {
        $messagePayload = $message->getContent()['payload'];
        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($messagePayload['stripeUserId']);
        assert(null !== $accountMapping);
        $url = $this->sellerOnboardingService->addLoginLinkToShop($accountMapping->getMiraklShopId(), $accountMapping);
        $this->logger->info("Received Stripe `account.updated` event, updated custom field to $url.");
    }

    public static function getHandledMessages(): iterable
    {
        yield AccountUpdateMessage::class => [
            'from_transport' => 'update_login_link',
        ];
    }
}
