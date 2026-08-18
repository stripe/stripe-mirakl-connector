<?php

namespace App\Command;

use App\Entity\PaymentMapping;
use App\Entity\StripePayout;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeRefundRepository;
use App\Repository\StripeTransferRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'connector:notify:failed-operation')]
class AlertingCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var PaymentMappingRepository
     */
    private $paymentMappingRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var StripeRefundRepository
     */
    private $stripeRefundRepository;

    /**
     * @var string
     */
    private $technicalEmailFrom;

    /**
     * @var string
     */
    private $technicalEmail;

    public function __construct(MailerInterface $mailer, PaymentMappingRepository $paymentMappingRepository, StripeTransferRepository $stripeTransferRepository, StripePayoutRepository $stripePayoutRepository, StripeRefundRepository $stripeRefundRepository, string $technicalEmailFrom, string $technicalEmail)
    {
        $this->mailer = $mailer;
        $this->paymentMappingRepository = $paymentMappingRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->technicalEmailFrom = $technicalEmailFrom;
        $this->technicalEmail = $technicalEmail;
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $this->logger->info('starting');
        $this->logger->info('<info>Sending alert email about failed payments, transfers, payouts and refunds</info>');

        $failedPayments = $this->paymentMappingRepository->findBy([
            'status' => [PaymentMapping::CAPTURE_FAILED, PaymentMapping::CANCEL_FAILED],
        ]);
        $this->logger->info(sprintf('Found %d payment(s) which failed capturing or canceling', count($failedPayments)));

        $failedTransfers = $this->stripeTransferRepository->findBy(['status' => StripeTransfer::getInvalidStatus()]);
        $this->logger->info(sprintf('Found %d transfer(s) which failed transfering', count($failedTransfers)));

        $failedPayouts = $this->stripePayoutRepository->findBy(['status' => StripePayout::getInvalidStatus()]);
        $this->logger->info(sprintf('Found %d payout(s) which failed transfering', count($failedPayouts)));

        $failedRefunds = $this->stripeRefundRepository->findBy(['status' => StripeRefund::getInvalidStatus()]);
        $this->logger->info(sprintf('Found %d refund(s) which failed transfering', count($failedRefunds)));

        if (0 === count($failedPayments) && 0 === count($failedTransfers) && 0 === count($failedPayouts) && 0 === count($failedRefunds)) {
            $this->logger->info('Exiting');
            $this->logger->info('job succeeded');

            return 0;
        }

        $displayPayment = function ($payment) {
            return [$payment->getId(), $payment->getMiraklCommercialOrderId(), $payment->getStripeChargeId(), $payment->getStripeAmount(), $payment->getStatus(), $payment->getStatusReason()];
        };
        $paymentTable = new Table($output);

        $paymentTable
            ->setHeaderTitle('Failed payments')
            ->setHeaders(['Internal ID', 'Mirakl Commercial Order ID', 'Stripe Charge ID', 'Amount', 'Status', 'Reason'])
            ->setRows(array_map($displayPayment, $failedPayments));
        $paymentTable->render();

        $displayTransfer = function ($transfer) {
            return [$transfer->getId(), $transfer->getMiraklId(), $transfer->getAmount(), $transfer->getStatus(), $transfer->getType(), $transfer->getStatusReason()];
        };
        $transferTable = new Table($output);

        $transferTable
            ->setHeaderTitle('Failed transfers')
            ->setHeaders(['Internal ID', 'Mirakl ID', 'Amount', 'Status', 'Type', 'Reason'])
            ->setRows(array_map($displayTransfer, $failedTransfers));
        $transferTable->render();

        $displayPayout = function ($payout) {
            return [$payout->getId(), $payout->getMiraklInvoiceId(), $payout->getAmount(), $payout->getStatus(), $payout->getStatusReason()];
        };
        $payoutTable = new Table($output);
        $payoutTable
            ->setHeaderTitle('Failed payouts')
            ->setHeaders(['Internal ID', 'Mirakl Invoice ID', 'Amount', 'Status', 'Reason'])
            ->setRows(array_map($displayPayout, $failedPayouts));
        $payoutTable->render();

        $displayRefund = function ($refund) {
            return [$refund->getMiraklRefundId(), $refund->getMiraklOrderId(), $refund->getAmount(), $refund->getStatus(), $refund->getStatusReason()];
        };
        $refundTable = new Table($output);
        $refundTable
            ->setHeaderTitle('Failed refunds')
            ->setHeaders(['Mirakl refund ID', 'Mirakl Order ID', 'Amount', 'Status', 'Reason'])
            ->setRows(array_map($displayRefund, $failedRefunds));
        $refundTable->render();

        $email = (new TemplatedEmail())
            ->from($this->technicalEmailFrom)
            ->to($this->technicalEmail)
            ->subject('[Stripe-Mirakl] Operation failed')
            ->htmlTemplate('emails/operationsFailed.html.twig')
            ->context([
                'payments' => $failedPayments,
                'transfers' => $failedTransfers,
                'payouts' => $failedPayouts,
                'refunds' => $failedRefunds,
            ]);

        $this->logger->info(sprintf('Sending alert email about %d failed payment(s), %d failed transfer(s), %d failed payout(s) and %d failed refund(s)', count($failedPayments), count($failedTransfers), count($failedPayouts), count($failedRefunds)), [
            'technicalEmailFrom' => $this->technicalEmailFrom,
            'technicalEmail' => $this->technicalEmail,
        ]);

        $this->logger->info('Sending email');
        $this->mailer->send($email);
        $this->logger->info('<info>Email sent</info>');
        $this->logger->info('job succeeded');

        return 0;
    }
}
