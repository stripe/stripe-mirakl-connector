<?php

namespace App\Command;

use App\Entity\StripeRefund;
use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Repository\StripeRefundRepository;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Mailer\MailerInterface;

class NotifyFailedOperationsCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:notify:failed-operation';

    /**
     * @var MailerInterface
     */
    private $mailer;

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

    public function __construct(MailerInterface $mailer, StripeTransferRepository $stripeTransferRepository, StripePayoutRepository $stripePayoutRepository, StripeRefundRepository $stripeRefundRepository, string $technicalEmailFrom, string $technicalEmail)
    {
        $this->mailer = $mailer;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeRefundRepository = $stripeRefundRepository;
        $this->technicalEmailFrom = $technicalEmailFrom;
        $this->technicalEmail = $technicalEmail;
        parent::__construct();
    }

    public function execute(InputInterface $input, OutputInterface $output): ?int
    {
        $output->writeln('<info>Sending alert email about failed transfers, payouts and refunds</info>');

        $failedTransfers = $this->stripeTransferRepository->findBy(['status' => StripeTransfer::getInvalidStatus()]);
        $output->writeln(sprintf('Found %d transfer(s) which failed transfering', count($failedTransfers)));

        $failedPayouts = $this->stripePayoutRepository->findBy(['status' => StripePayout::getInvalidStatus()]);
        $output->writeln(sprintf('Found %d payout(s) which failed transfering', count($failedPayouts)));

        $failedRefunds = $this->stripeRefundRepository->findBy(['status' => StripeRefund::getInvalidStatus()]);
        $output->writeln(sprintf('Found %d refund(s) which failed transfering', count($failedRefunds)));

        if (0 === count($failedTransfers) && 0 === count($failedPayouts) && 0 === count($failedRefunds)) {
            $output->writeln('Exiting');

            return 0;
        }

        $displayTransfer = function ($transfer) {
            return [$transfer->getId(), $transfer->getMiraklId(), $transfer->getAmount(), $transfer->getStatus(), $transfer->getType(), $transfer->getFailedReason()];
        };
        $transferTable = new Table($output);
        $transferTable
            ->setHeaderTitle('Failed transfers')
            ->setHeaders(['Internal ID', 'Mirakl ID', 'Amount', 'Status', 'Type', 'Reason'])
            ->setRows(array_map($displayTransfer, $failedTransfers));
        $transferTable->render();

        $displayPayout = function ($payout) {
            return [$payout->getId(), $payout->getMiraklInvoiceId(), $payout->getAmount(), $payout->getStatus(), $payout->getFailedReason()];
        };
        $payoutTable = new Table($output);
        $payoutTable
            ->setHeaderTitle('Failed payouts')
            ->setHeaders(['Internal ID', 'Mirakl Invoice ID', 'Amount', 'Status', 'Reason'])
            ->setRows(array_map($displayPayout, $failedPayouts));
        $payoutTable->render();

        $displayRefund = function ($refund) {
            return [$refund->getMiraklRefundId(), $refund->getMiraklOrderId(), $refund->getAmount(), $refund->getStatus(), $refund->getFailedReason()];
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
            ->subject('[Stripe-Mirakl] Transfer failed')
            ->htmlTemplate('emails/operationsFailed.html.twig')
            ->context([
                'transfers' => $failedTransfers,
                'payouts' => $failedPayouts,
                'refunds' => $failedRefunds,
            ]);

        $this->logger->info(sprintf('Sending alert email about %d failed transfer(s), %d failed payout(s) and %d failed refund(s)', count($failedTransfers), count($failedPayouts), count($failedRefunds)), [
            'technicalEmailFrom' => $this->technicalEmailFrom,
            'technicalEmail' => $this->technicalEmail,
        ]);

        $output->writeln('Sending email');
        $this->mailer->send($email);
        $output->writeln('<info>Email sent</info>');

        return 0;
    }
}
