<?php

namespace App\Handler;

use App\Exception\InvalidStripeAccountException;
use App\Message\AccountUpdateMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Account;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;

class UpdateKYCStatusHandler implements MessageHandlerInterface, MessageSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const CURRENTLY_DUE = 'currently_due';
    public const PENDING_VERIFICATION = 'pending_verification';
    public const DISABLED_REASON = 'disabled_reason';
    public const KYC_STATUS_APPROVED = 'APPROVED';
    public const KYC_STATUS_REFUSED = 'REFUSED';
    public const KYC_STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const KYC_STATUS_PENDING_SUBMISSION = 'PENDING_SUBMISSION';

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    public function __construct(MiraklClient $miraklClient, StripeClient $stripeClient)
    {
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
    }

    public function __invoke(AccountUpdateMessage $message)
    {
        $messagePayload = $message->getContent()['payload'];
        $this->logger->info('Received Stripe `account.updated` webhook. Updating KYC status.', $messagePayload);

        $stripeAccount = $this->stripeClient->accountRetrieve($messagePayload['stripeUserId']);

        $this->miraklClient->patchShops([
            [
                'shop_id' => $messagePayload['miraklShopId'],
                'kyc' => [
                    'status' => $this->getKYCStatus($stripeAccount),
                ]
            ],
        ]);
    }

    public static function getHandledMessages(): iterable
    {
        yield AccountUpdateMessage::class => [
            'from_transport' => 'update_kyc_status',
        ];
    }

    private function getKYCStatus(Account $stripeAccount): string
    {
        $requirements = $stripeAccount->requirements;

        if (count($requirements[self::CURRENTLY_DUE]) > 0) {
            return self::KYC_STATUS_PENDING_SUBMISSION;
        }

        if (count($requirements[self::PENDING_VERIFICATION]) > 0) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }

        if (
            $requirements[self::DISABLED_REASON] !== ''
            && strpos($requirements[self::DISABLED_REASON], 'rejected') === 0
        ) {
            return self::KYC_STATUS_REFUSED;
        }

        if ($requirements[self::DISABLED_REASON] !== '' && $requirements[self::DISABLED_REASON] !== null) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }

        if ($stripeAccount->payouts_enabled && $stripeAccount->charges_enabled) {
            return self::KYC_STATUS_APPROVED;
        }

        $this->logger->error(sprintf('Could not calculate KYC status for account %s', $stripeAccount->id), [
            'requirements' => $requirements,
        ]);

        throw new InvalidStripeAccountException();
    }
}
