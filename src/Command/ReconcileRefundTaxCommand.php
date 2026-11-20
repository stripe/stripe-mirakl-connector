<?php

namespace App\Command;

use App\Entity\StripeRefund;
use App\Repository\StripeRefundRepository;
use App\Service\MiraklClient;
use App\Service\StripeAmount;
use App\Service\StripeClient;
use Stripe\Charge;
use Stripe\Refund;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'connector:refund:reconcile-tax')]
class ReconcileRefundTaxCommand extends Command
{
    public function __construct(
        private MiraklClient $miraklClient,
        private StripeClient $stripeClient,
        private StripeRefundRepository $stripeRefundRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Create missing tax top-up refunds in Stripe.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = (bool) $input->getOption('execute');
        $refunds = $this->stripeRefundRepository->findBy([
            'status' => [StripeRefund::REFUND_CREATED, StripeRefund::REFUND_FAILED],
            'type' => [StripeRefund::REFUND_PRODUCT_ORDER, StripeRefund::REFUND_SERVICE_ORDER],
        ], ['id' => 'ASC']);

        $checked = 0;
        $topUps = 0;
        $candidates = 0;
        foreach ($refunds as $refund) {
            if (!$refund->getStripeRefundId() || $refund->getTaxTopUpStripeRefundId()) {
                continue;
            }

            ++$checked;
            $order = StripeRefund::REFUND_SERVICE_ORDER === $refund->getType()
                ? current($this->miraklClient->listServiceOrdersById([$refund->getMiraklOrderId()]))
                : current($this->miraklClient->listProductOrdersById([$refund->getMiraklOrderId()]));
            if (!$order || $order->isTaxIncluded()) {
                continue;
            }

            $taxAmount = StripeAmount::toMinorUnits(
                $order->getRefundedTax($refund),
                $refund->getCurrency()
            );
            if ($taxAmount <= 0) {
                continue;
            }

            $expectedAmount = $refund->getMiraklAmount() + $taxAmount;
            $existingStripeRefund = $this->stripeClient->refundRetrieve($refund->getStripeRefundId());
            $actualAmount = (int) $existingStripeRefund->amount;
            if (!in_array($existingStripeRefund->status, ['succeeded', 'pending'], true)) {
                $output->writeln(sprintf(
                    'SKIP refund %s: Stripe refund status is %s.',
                    $refund->getMiraklRefundId(),
                    $existingStripeRefund->status
                ));

                continue;
            }
            if ($actualAmount >= $expectedAmount) {
                continue;
            }

            $difference = $expectedAmount - $actualAmount;
            $charge = $this->getChargeForRefund($existingStripeRefund, $refund);
            if (!$charge) {
                $output->writeln(sprintf('SKIP refund %s: cannot find its charge.', $refund->getMiraklRefundId()));

                continue;
            }
            $available = (int) $charge->amount - (int) $charge->amount_refunded;
            if ($available < $difference) {
                $output->writeln(sprintf(
                    'SKIP refund %s: tax top-up %d exceeds charge capacity %d.',
                    $refund->getMiraklRefundId(),
                    $difference,
                    max(0, $available)
                ));

                continue;
            }

            $output->writeln(sprintf(
                '%s refund %s: existing %d, expected %d, tax top-up %d.',
                $execute ? 'TOP-UP' : 'DRY-RUN',
                $refund->getMiraklRefundId(),
                $actualAmount,
                $expectedAmount,
                $difference
            ));
            ++$candidates;
            if (!$execute) {
                continue;
            }

            $topUp = $this->stripeClient->createRefund(
                $charge->id,
                $difference,
                [
                    'miraklRefundId' => $refund->getMiraklRefundId(),
                    'taxTopUpForStripeRefundId' => $refund->getStripeRefundId(),
                ],
                sprintf('mirakl-tax-top-up-%d', $refund->getId())
            );
            $refund->setTaxTopUpStripeRefundId($topUp->id)
                ->setTaxTopUpAmount($difference);
            $this->stripeRefundRepository->flush();
            ++$topUps;
        }

        $output->writeln(sprintf(
            '%s: checked %d refunds; %d tax top-up(s) %s.',
            $execute ? 'Reconciliation complete' : 'Dry run complete',
            $checked,
            $execute ? $topUps : $candidates,
            $execute ? 'created' : 'available'
        ));

        return Command::SUCCESS;
    }

    private function getChargeForRefund(Refund $stripeRefund, StripeRefund $refund): ?Charge
    {
        $originalCharge = $stripeRefund->charge ?? null;
        if ($originalCharge instanceof Charge) {
            return $originalCharge;
        }
        if (is_string($originalCharge) && '' !== $originalCharge) {
            return $this->stripeClient->chargeRetrieve($originalCharge);
        }

        $transactionId = $refund->getTransactionId();
        if (!$transactionId) {
            return null;
        }

        if (str_starts_with($transactionId, 'pi_')) {
            // Do not guess which PaymentIntent charge backed the refund. The
            // original Stripe refund should provide its charge above.
            return null;
        }

        if (str_starts_with($transactionId, 'ch_') || str_starts_with($transactionId, 'py_')) {
            return $this->stripeClient->chargeRetrieve($transactionId);
        }

        return null;
    }
}
