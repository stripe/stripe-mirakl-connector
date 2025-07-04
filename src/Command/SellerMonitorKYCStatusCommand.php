<?php

namespace App\Command;

use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Service\ConfigService;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Stripe\Account;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class SellerMonitorKYCStatusCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:monitor-kyc-status';

    public const CURRENTLY_DUE = 'currently_due';
    public const PENDING_VERIFICATION = 'pending_verification';
    public const DISABLED_REASON = 'disabled_reason';
    public const KYC_STATUS_APPROVED = 'APPROVED';
    public const KYC_STATUS_REFUSED = 'REFUSED';
    public const KYC_STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';
    public const KYC_STATUS_PENDING_SUBMISSION = 'PENDING_SUBMISSION';

    /**
     * @var MessageBusInterface
     */
    private $bus;

    public function getBus(): mixed
    {
        return $this->bus;
    }

    /**
     * @var AccountMappingRepository
     */
    private $accountMappingRepository;

    public function setAccountMappingRepository(AccountMappingRepository $accountMappingRepository): void
    {
        $this->accountMappingRepository = $accountMappingRepository;
    }

    /**
     * @var ConfigService
     */
    private $configService;

    public function getConfigService(): mixed
    {
        return $this->configService;
    }

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeClient
     */
    private $stripeClient;

    public function setStripeClient(StripeClient $stripeClient): void
    {
        $this->stripeClient = $stripeClient;
    }

    private $mirakl_shop_kyc_disable;

    /**
     * @var MailerInterface
     */
    private $mailer;

    public function setMailer(MailerInterface $mailer): void
    {
        $this->mailer = $mailer;
    }

    /**
     * @var string
     */
    private $technicalEmailFrom;

    public function setTechnicalEmailFrom(string $email): void
    {
        $this->technicalEmailFrom = $email;
    }

    /**
     * @var string
     */
    private $technicalEmail;

    public function setTechnicalEmail(string $email): void
    {
        $this->technicalEmail = $email;
    }

    /**
     * @var string
     */
    private $customFieldCode;

    public function __construct(
        MessageBusInterface $bus,
        ConfigService $configService,
        AccountMappingRepository $accountMappingRepository,
        MiraklClient $miraklClient,
        StripeClient $stripeClient,
        bool $miraklShopKycDisable,
        MailerInterface $mailer,
        string $technicalEmailFrom,
        string $technicalEmail,
        string $customFieldCode
    ) {
        $this->bus = $bus;
        $this->configService = $configService;
        $this->miraklClient = $miraklClient;
        $this->stripeClient = $stripeClient;
        $this->accountMappingRepository = $accountMappingRepository;
        $this->mirakl_shop_kyc_disable = $miraklShopKycDisable;
        $this->mailer = $mailer;
        $this->technicalEmail = $technicalEmail;
        $this->technicalEmailFrom = $technicalEmailFrom;
        $this->customFieldCode = $customFieldCode;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger->info('starting');
        echo 'Process Start' . PHP_EOL;

        $stripeAccounts = $this->stripeClient->retrieveAllAccounts();
        $accountsInDB = $this->accountMappingRepository->findAll();

        $miraklUpdateShopKycReqs = [];
        $kycRequiredAccountDets = [];

        foreach ($stripeAccounts as $stripeAccount) {

            echo '----------------- Start of record -----------------' . PHP_EOL;
            $kycStatus = $this->getKYCStatus($stripeAccount);
            echo 'KYC Disabled Status: ' . $kycStatus . PHP_EOL;

            $dbAccount = $this->findAccount($stripeAccount->id, $accountsInDB);
            echo 'Stripe Account ID: ' . $stripeAccount->id . ', KYC Status: ' . $kycStatus . PHP_EOL;

            if ('' != $kycStatus && null != $dbAccount) {
                $dbAccount->setIgnored(true);
                $this->accountMappingRepository->persistAndFlush($dbAccount);

                $accountLink = $this->stripeClient->createAccountLink($stripeAccount->id, 'https://dashboard.stripe.com', 'https://dashboard.stripe.com');

                $miraklUpdateShopKycReqs[] = [
                    'shop_id' => $dbAccount->getMiraklShopId(),
                     'kyc' => [
                         'status' => $kycStatus,
                         'reason' => 'STRIPE CONNECT ACCOUNT DISABLEMENT'
                     ],
                    'shop_additional_fields' => [
                        [
                            'code' => $this->customFieldCode,
                            'value' => $accountLink->url
                        ]
                    ]
                ];

                $kycRequiredAccountDets[] = [
                    'id' => $dbAccount->getId(),
                    'shop_id' => $dbAccount->getMiraklShopId(),
                    'connect_account_id' => $stripeAccount->id,
                    'reason' => $kycStatus
                ];
            } elseif ('' == $kycStatus && null != $dbAccount) {
                //$dbAccount->setIgnored(false);
                $this->accountMappingRepository->persistAndFlush($dbAccount);

                $accountLink = $this->stripeClient->createLoginLink($stripeAccount->id);

                $miraklUpdateShopKycReqs[] = [
                    'shop_id' => $dbAccount->getMiraklShopId(),
                    'kyc' => [
                        'status' => 'APPROVED',
                        'reason' => 'KYC approved'
                    ],
                    'shop_additional_fields' => [
                        [
                            'code' => $this->customFieldCode,
                            'value' => $accountLink->url
                        ]
                    ]
                ];
            }
        }

        echo 'Mirakl Shop KYC: ' . $this->mirakl_shop_kyc_disable . PHP_EOL;
        echo 'Count Update Shop KYC: ' . count($miraklUpdateShopKycReqs) . PHP_EOL;
        echo '----------------- End of record -----------------' . PHP_EOL;

        if ($this->mirakl_shop_kyc_disable && count($miraklUpdateShopKycReqs) > 0) {
            $this->miraklClient->updateShopKycStatusWithReason($miraklUpdateShopKycReqs);
        }

        if (count($kycRequiredAccountDets) > 0) {
            $email = (new TemplatedEmail())
            ->from($this->technicalEmailFrom)
            ->to($this->technicalEmail)
            ->subject('[Stripe-Mirakl] KYC Required')
            ->htmlTemplate('emails/kycRequired.html.twig')
            ->context([
                'kycRequiredAccounts' => $kycRequiredAccountDets,
            ]);
            $this->mailer->send($email);
        }

        $this->logger->info('job succeeded');
        echo 'Process End';

        return 0;
    }

    private function findAccount(string $stripeId, array $accountsInDB): ?AccountMapping
    {
        foreach ($accountsInDB as $acc) {
            if ($stripeId == (string) $acc->getStripeAccountId()) {
                return $acc;
            }
        }

        return null;
    }

    private function getKYCStatus(Account $stripeAccount): string
    {
        $requirements = $stripeAccount->requirements;

        if (isset($requirements[self::CURRENTLY_DUE]) && count((array) $requirements[self::CURRENTLY_DUE]) > 0) {
            return self::KYC_STATUS_PENDING_SUBMISSION;
        }

        if (isset($requirements[self::PENDING_VERIFICATION]) && count((array) $requirements[self::PENDING_VERIFICATION]) > 0) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }
        $disabledReason = isset($requirements[self::DISABLED_REASON]) ? ''.$requirements[self::DISABLED_REASON] : '';
        if (isset($requirements[self::DISABLED_REASON]) && '' !== $requirements[self::DISABLED_REASON]
            && 0 === strpos($disabledReason, 'rejected')) {
            return self::KYC_STATUS_REFUSED;
        }

        if (isset($requirements[self::DISABLED_REASON]) && '' !== $requirements[self::DISABLED_REASON] && null !== $requirements[self::DISABLED_REASON]) {
            return self::KYC_STATUS_PENDING_APPROVAL;
        }

        return '';
    }
}
