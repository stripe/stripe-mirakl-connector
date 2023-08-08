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
        echo 'process start';

        $stripeAccts = $this->stripeClient->retrieveAllAccounts();

        //  echo count($stripeAccts);

        $accountsInDB = $this->accountMappingRepository->findAll();

        $mirakl_update_shop_kyc_reqs = [];

        $kycRequiredAccountDets = [];

        foreach ($stripeAccts as $st_acc) {
            $kyc_disabled_status = $this->getKYCStatus($st_acc);

            // echo "\n-----".$st_acc->id."  ".$kyc_disabled_status."mmmmm";

            $dbAccount = $this->findAccount($st_acc->id, $accountsInDB);

            if ('' != $kyc_disabled_status && null != $dbAccount) {
                $dbAccount->setIgnored(true);
                $this->accountMappingRepository->persistAndFlush($dbAccount);

                $account_link = $this->stripeClient->createAccountLink($st_acc->id, 'https://dashboard.stripe.com', 'https://dashboard.stripe.com');

                $mirakl_update_shop_kyc_reqs[] = ['shop_id' => $dbAccount->getMiraklShopId(),
                         'kyc' => ['status' => $kyc_disabled_status, 'reason' => 'STRIPE CONNECT ACCOUNT DISABLEMENT'],
                    'shop_additional_fields' => [['code' => $this->customFieldCode, 'value' => $account_link->url]],
                ];

                $kycRequiredAccountDets[] = ['id' => $dbAccount->getId(), 'shop_id' => $dbAccount->getMiraklShopId(),
                    'connect_account_id' => $st_acc->id, 'reason' => $kyc_disabled_status];
            } elseif ('' == $kyc_disabled_status && null != $dbAccount && $dbAccount->getIgnored()) {
                $dbAccount->setIgnored(false);
                $this->accountMappingRepository->persistAndFlush($dbAccount);

                $account_link = $this->stripeClient->createLoginLink($st_acc->id);

                $mirakl_update_shop_kyc_reqs[] = ['shop_id' => $dbAccount->getMiraklShopId(),
                    'kyc' => ['status' => 'APPROVED', 'reason' => 'KYC aaproved'],
                    'shop_additional_fields' => [['code' => $this->customFieldCode, 'value' => $account_link->url]],
                ];
            }
        }

        echo $this->mirakl_shop_kyc_disable;

        if ($this->mirakl_shop_kyc_disable && count($mirakl_update_shop_kyc_reqs) > 0) {
            $this->miraklClient->updateShopKycStatusWithReason($mirakl_update_shop_kyc_reqs);
        }

        // print_r($kycRequiredAccountDets);

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
        echo 'process end';

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
