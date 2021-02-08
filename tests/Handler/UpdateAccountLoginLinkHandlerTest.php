<?php

namespace App\Tests\MessageHandler;

use App\Factory\MiraklPatchShopFactory;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Message\AccountUpdateMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\LoginLink;

class UpdateAccountLoginLinkHandlerTest extends TestCase
{
    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    /**
     * @var MiraklPatchShopFactory
     */
    private $patchFactory;

    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        $this->miraklClient = $this->createMock(MiraklClient::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->patchFactory = $this->getMockBuilder(MiraklPatchShopFactory::class)
                         ->setConstructorArgs(['stripe-link'])
                         ->getMock();

        $this->handler = new UpdateAccountLoginLinkHandler(
            $this->miraklClient,
            $this->stripeClient,
            $this->patchFactory
        );

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    public function testNominalShopPatch()
    {
        $stripeLoginLink = new LoginLink();
        $stripeLoginLink['url'] = 'https://stripe-login-link';

        $this->stripeClient
            ->expects($this->once())
            ->method('accountCreateLoginLink')
            ->with('acct_stripe_account')
            ->willReturn($stripeLoginLink);

        $this->patchFactory
            ->expects($this->once())
            ->method('setMiraklShopId')
            ->with(2000)
            ->willReturn($this->patchFactory);
        $this->patchFactory
            ->expects($this->once())
            ->method('setStripeUrl')
            ->with('https://stripe-login-link')
            ->willReturn($this->patchFactory);
        $this->patchFactory
            ->expects($this->once())
            ->method('buildPatch')
            ->willReturn(['generatedPatch']);

        $this->miraklClient
            ->expects($this->once())
            ->method('patchShops')
            ->with([['generatedPatch']]);

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
