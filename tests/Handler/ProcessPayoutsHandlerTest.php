<?php

namespace App\Tests\MessageHandler;

use App\Entity\AccountMapping;
use App\Entity\StripePayout;
use App\Handler\ProcessPayoutHandler;
use App\Message\PayoutFailedMessage;
use App\Message\ProcessPayoutMessage;
use App\Repository\AccountMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessPayoutsHandlerTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();

        $this->httpNotificationReceiver = self::$container->get('messenger.transport.operator_http_notification');

        $this->accountMappingRepository = $container->get('doctrine')->getRepository(AccountMapping::class);
        $this->stripePayoutRepository = $container->get('doctrine')->getRepository(StripePayout::class);

        $this->handler = new ProcessPayoutHandler(
            $container->get('App\Service\StripeClient'),
            $this->stripePayoutRepository,
            self::$container->get(MessageBusInterface::class)
        );
        $this->handler->setLogger(new NullLogger());
    }

    private function executeHandler($stripePayoutId)
    {
        ($this->handler)(new ProcessPayoutMessage($stripePayoutId));
    }

    private function mockPayout($accountId = StripeMock::ACCOUNT_BASIC)
    {
        $accountMapping = $this->accountMappingRepository->findOneBy([
            'stripeAccountId' => $accountId
        ]);

        $payout = new StripePayout();
        $payout->setAccountMapping($accountMapping);
        $payout->setMiraklInvoiceId(MiraklMock::INVOICE_BASIC);
        $payout->setAmount(1234);
        $payout->setCurrency('eur');
        $payout->setStatus(StripePayout::PAYOUT_PENDING);

        $this->stripePayoutRepository->persistAndFlush($payout);

        return $payout;
    }

    public function testValidPayout()
    {
        $payout = $this->mockPayout(StripeMock::ACCOUNT_BASIC);
        $this->executeHandler($payout->getId());

        $payout = $this->stripePayoutRepository->findOneBy([
            'id' => $payout->getId()
        ]);

        $this->assertEquals(StripePayout::PAYOUT_CREATED, $payout->getStatus());
        $this->assertEquals(StripeMock::PAYOUT_BASIC, $payout->getPayoutId());
    }

    public function testPayoutWithStripeError()
    {
        $payout = $this->mockPayout(StripeMock::ACCOUNT_PAYOUT_DISABLED);
        $this->executeHandler($payout->getId());

        $payout = $this->stripePayoutRepository->findOneBy([
            'id' => $payout->getId()
        ]);

        $this->assertEquals(StripePayout::PAYOUT_FAILED, $payout->getStatus());
        $this->assertTrue($this->hasNotification(
            PayoutFailedMessage::class,
            [
                'type' => 'payout.failed',
                'payload' => [
                    'internalId' => $payout->getId(),
                    'miraklInvoiceId' => MiraklMock::INVOICE_BASIC,
                    'amount' => 1234,
                    'currency' => 'eur',
                    'stripePayoutId' => null,
                    'payoutId' => null,
                    'status' => StripePayout::PAYOUT_FAILED,
                    'failedReason' => 'Payouts disabled',
                ]
            ]
        ));
    }

    private function hasNotification($class, $content): bool
    {
        foreach ($this->httpNotificationReceiver->get() as $messageEnvelope) {
            $message = $messageEnvelope->getMessage();
            if ($message instanceof $class && $message->getContent() == $content) {
                return true;
            }
        }

        return false;
    }
}
