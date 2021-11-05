<?php

namespace App\Tests\MessageHandler;

use App\Exception\InvalidStripeAccountException;
use App\Factory\MiraklPatchShopFactory;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Handler\UpdateKYCStatusHandler;
use App\Message\AccountUpdateMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Account;

class UpdateKYCStatusHandlerTest extends TestCase
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

        $this->handler = new UpdateKYCStatusHandler($this->miraklClient, $this->stripeClient);

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }

    /**
     * @dataProvider getCorrectKYCStatusData
     */
    public function testShouldUpdateCorrectKYCStatus($requirements, $payoutEnabled, $chargesEnabled, $KYCStatus)
    {
        $stripeAccount = new Account('acct_valid');
        $stripeAccount->requirements = $requirements;
        $stripeAccount->payouts_enabled = $payoutEnabled;
        $stripeAccount->charges_enabled = $chargesEnabled;

        $this->stripeClient
            ->expects($this->once())
            ->method('accountRetrieve')
            ->with('acct_valid')
            ->willReturn($stripeAccount);

        $this->miraklClient
            ->expects($this->once())
            ->method('patchShops')
            ->with([
                [
                    'shop_id' => 2000,
                    'kyc' => [
                        'status' => $KYCStatus,
                    ]
                ]
            ]);

        $message = new AccountUpdateMessage(2000, 'acct_valid');

        $handler = $this->handler;
        $handler($message);
    }

    public function getCorrectKYCStatusData(): \Generator
    {
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => ['not_empty'],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => false,
            'chargesEnabled' => false,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_PENDING_SUBMISSION
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => ['not_empty'],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => false,
            'chargesEnabled' => true,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_PENDING_APPROVAL
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => 'rejected.test',
            ],
            'payoutEnabled' => true,
            'chargesEnabled' => false,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_REFUSED
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => 'not_rejected',
            ],
            'payoutEnabled' => true,
            'chargesEnabled' => false,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_PENDING_APPROVAL
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => null,
            ],
            'payoutEnabled' => true,
            'chargesEnabled' => true,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_APPROVED
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => true,
            'chargesEnabled' => true,
            'KYCStatus' => UpdateKYCStatusHandler::KYC_STATUS_APPROVED
        ];
    }

    /**
     * @dataProvider getInvalidAccountData
     */
    public function testShouldThrowWhenInvalidAccount($requirements, $payoutEnabled, $chargesEnabled)
    {
        $stripeAccount = new Account('acct_valid');
        $stripeAccount->requirements = $requirements;
        $stripeAccount->payouts_enabled = $payoutEnabled;
        $stripeAccount->charges_enabled = $chargesEnabled;

        $this->stripeClient
            ->expects($this->once())
            ->method('accountRetrieve')
            ->with('acct_invalid')
            ->willReturn($stripeAccount);

        $this->expectException(InvalidStripeAccountException::class);

        $message = new AccountUpdateMessage(2000, 'acct_invalid');

        $handler = $this->handler;
        $handler($message);
    }

    public function getInvalidAccountData(): \Generator
    {
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => false,
            'chargesEnabled' => false,
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => false,
            'chargesEnabled' => true,
        ];
        yield [
            'requirements' => [
                UpdateKYCStatusHandler::CURRENTLY_DUE => [],
                UpdateKYCStatusHandler::PENDING_VERIFICATION => [],
                UpdateKYCStatusHandler::DISABLED_REASON => '',
            ],
            'payoutEnabled' => true,
            'chargesEnabled' => false,
        ];
    }

    public function testGetHandledMessage()
    {
        $handledMessage = iterator_to_array(UpdateKYCStatusHandler::getHandledMessages());
        $this->assertEquals([
            AccountUpdateMessage::class => [
                'from_transport' => 'update_kyc_status',
            ],
        ], $handledMessage);
    }
}
