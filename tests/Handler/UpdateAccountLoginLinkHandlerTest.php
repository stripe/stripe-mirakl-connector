<?php

namespace App\Tests\MessageHandler;

use App\Entity\AccountMapping;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\AccountUpdateMessage;
use App\Repository\AccountMappingRepository;
use App\Service\SellerOnboardingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class UpdateAccountLoginLinkHandlerTest extends TestCase
{

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var SellerOnboardingService
     */
    private $sellerOnboardingService;

    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        $this->accountMappingRepository = $this->createMock(AccountMappingRepository::class);
        $this->sellerOnboardingService = $this->createMock(SellerOnboardingService::class);

        $this->handler = new UpdateAccountLoginLinkHandler(
            $this->accountMappingRepository,
            $this->sellerOnboardingService
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testNominalShopPatch()
    {
        $url = 'https://stripe-login-link';

        $accountMapping = new AccountMapping();
        $accountMapping->setStripeAccountId('acct_stripe_account');
        $accountMapping->setMiraklShopId(2000);
        $this->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByStripeAccountId')
            ->with('acct_stripe_account')
            ->willReturn($accountMapping);


        $this->sellerOnboardingService
            ->expects($this->once())
            ->method('addLoginLinkToShop')
            ->with(2000, $accountMapping);

        $message = new AccountUpdateMessage(2000, 'acct_stripe_account');

        $handler = $this->handler;
        $handler($message);
    }

    public function testGetHandledMessage()
    {
        $handledMessage = iterator_to_array(UpdateAccountLoginLinkHandler::getHandledMessages());
        $this->assertEquals([
            AccountUpdateMessage::class => [
                'from_transport' => 'update_login_link',
            ],
        ], $handledMessage);
    }
}
