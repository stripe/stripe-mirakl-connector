<?php

namespace App\Command;

use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Exception\UndispatchableException;
use App\Message\ProcessPayoutMessage;
use App\Message\ProcessTransferMessage;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Repository\StripeTransferRepository;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

class ProcessPayoutCommand extends Command implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected static $defaultName = 'connector:dispatch:process-payout';

    protected static $typeToAmountKey = [
        StripeTransfer::TRANSFER_SUBSCRIPTION => 'total_subscription_incl_tax',
        StripeTransfer::TRANSFER_EXTRA_CREDITS => 'total_other_credits_incl_tax',
        StripeTransfer::TRANSFER_EXTRA_INVOICES => 'total_other_invoices_incl_tax',
    ];

    /**
     * @var MessageBusInterface
     */
    private $bus;

    /**
     * @var MiraklClient
     */
    private $miraklClient;

    /**
     * @var StripeProxy
     */
    private $stripeProxy;

    /**
     * @var StripePayoutRepository
     */
    private $stripePayoutRepository;

    /**
     * @var StripeTransferRepository
     */
    private $stripeTransferRepository;

    /**
     * @var MiraklStripeMappingRepository
     */
    private $miraklStripeMappingRepository;

    public function __construct(MessageBusInterface $bus, MiraklClient $miraklClient, StripeProxy $stripeProxy, StripePayoutRepository $stripePayoutRepository, StripeTransferRepository $stripeTransferRepository, MiraklStripeMappingRepository $miraklStripeMappingRepository)
    {
        $this->bus = $bus;
        $this->miraklClient = $miraklClient;
        $this->stripeProxy = $stripeProxy;
        $this->stripePayoutRepository = $stripePayoutRepository;
        $this->stripeTransferRepository = $stripeTransferRepository;
        $this->miraklStripeMappingRepository = $miraklStripeMappingRepository;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addArgument('mirakl_shop_id', InputArgument::OPTIONAL);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $miraklShopId = $input->getArgument('mirakl_shop_id');

        assert(null === $miraklShopId || is_string($miraklShopId));
        if (null == $miraklShopId) {
            $output->writeln('No mirakl shop id specified');
            $miraklShopId = '';
        }

        if ('' !== $miraklShopId) {
            $this->logger->info('Creating specified Mirakl invoices', ['mirakl_shop_id' => $miraklShopId]);
            $miraklInvoices = $this->miraklClient->listMiraklInvoices(null, $miraklShopId);
        } else {
            $lastMiraklUpdateTime = $this->stripePayoutRepository->getLastMiraklUpdateTime();
            $miraklInvoices = $this->miraklClient->listMiraklInvoices($lastMiraklUpdateTime, null);
        }
        $this->createInvoices($miraklInvoices);
        $output->writeln('Dispatching in process_payout queue');

        return 0;
    }

    private function createInvoices(array $miraklInvoicesToCreate): void
    {
        if (0 === count($miraklInvoicesToCreate)) {
            return;
        }
        $toCreateIds = array_column($miraklInvoicesToCreate, 'invoice_id');

        $alreadyCreatedInvoiceIds = $this->stripePayoutRepository->findAlreadyCreatedInvoiceIds($toCreateIds);
        foreach ($miraklInvoicesToCreate as $miraklInvoice) {
            if (in_array($miraklInvoice['invoice_id'], $alreadyCreatedInvoiceIds)) {
                continue;
            }
            $this->createStripePayout($miraklInvoice);
        }

        $alreadyCreatedTransferIds = $this->stripeTransferRepository->findAlreadyCreatedMiraklIds($toCreateIds);
        foreach ($miraklInvoicesToCreate as $miraklInvoice) {
            if (in_array($miraklInvoice['invoice_id'], $alreadyCreatedTransferIds)) {
                continue;
            }
            $this->createStripeTransfer($miraklInvoice, StripeTransfer::TRANSFER_SUBSCRIPTION);
            $this->createStripeTransfer($miraklInvoice, StripeTransfer::TRANSFER_EXTRA_CREDITS);
            $this->createStripeTransfer($miraklInvoice, StripeTransfer::TRANSFER_EXTRA_INVOICES);
        }
    }

    private function createStripeTransfer(array $miraklInvoice, string $type)
    {
        try {
            $amount = $this->getAmount($miraklInvoice, self::$typeToAmountKey[$type]);
            $miraklUpdateTime = $this->getMiraklUpdateTime($miraklInvoice);
        } catch (UndispatchableException $e) {
            $this->logger->info('Not dispatching', [
                'Reason' => $e->getMessage(),
                'miraklInvoiceId' => $miraklInvoice['invoice_id'],
                'type' => $type,
            ]);

            return;
        }

        $newlyCreatedTransfer = new StripeTransfer();
        $miraklStripeMapping = $this->getMiraklStripeMapping($miraklInvoice);
        if (null === $miraklStripeMapping) {
            $failedReason = sprintf('Stripe-Mirakl Mapping associated with Mirakl Shop ID %s does not exist', $miraklInvoice['shop_id']);
            $this->logger->error($failedReason, [
                'miraklShopId' => $miraklInvoice['shop_id'],
            ]);
            $newlyCreatedTransfer
                ->setStatus(StripeTransfer::TRANSFER_FAILED)
                ->setFailedReason($failedReason);
        } else {
            $newlyCreatedTransfer->setMiraklStripeMapping($miraklStripeMapping);
        }

        $newlyCreatedTransfer
            ->setAmount($amount)
            ->setMiraklId($miraklInvoice['invoice_id'])
            ->setCurrency($miraklInvoice['currency_iso_code'])
            ->setMiraklUpdateTime($miraklUpdateTime)
            ->setType($type)
            ->setStatus(StripeTransfer::TRANSFER_PENDING);
        try {
            $this->stripeTransferRepository->persistAndFlush($newlyCreatedTransfer);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->info('Stripe Transfer already exists but not created on Stripe', [
                'miraklInvoice' => $miraklInvoice,
            ]);
        }

        // Id can never be null after persist
        assert(null !== $newlyCreatedTransfer->getId());
        $message = new ProcessTransferMessage($type, $newlyCreatedTransfer->getId());
        $this->bus->dispatch($message);
    }

    private function createStripePayout(array $miraklInvoice)
    {
        try {
            $amount = $this->getAmount($miraklInvoice, 'amount_transferred');
            $miraklUpdateTime = $this->getMiraklUpdateTime($miraklInvoice);
        } catch (UndispatchableException $e) {
            $this->logger->info('Not dispatching', [
                'Reason' => $e,
            ]);

            return;
        }
        $newlyCreatedPayout = new StripePayout();
        $newlyCreatedPayout->setStatus(StripePayout::PAYOUT_PENDING);

        $miraklStripeMapping = $this->getMiraklStripeMapping($miraklInvoice);
        if (null === $miraklStripeMapping) {
            $failedReason = sprintf('Stripe-Mirakl Mapping associated with Mirakl Shop ID %s does not exist', $miraklInvoice['shop_id']);
            $this->logger->error($failedReason, [
                'miraklShopId' => $miraklInvoice['shop_id'],
            ]);
            $newlyCreatedPayout
                ->setStatus(StripePayout::PAYOUT_FAILED)
                ->setFailedReason($failedReason);
        } else {
            $newlyCreatedPayout->setMiraklStripeMapping($miraklStripeMapping);
        }

        $newlyCreatedPayout
            ->setAmount($amount)
            ->setMiraklInvoiceId($miraklInvoice['invoice_id'])
            ->setCurrency($miraklInvoice['currency_iso_code'])
            ->setMiraklUpdateTime($miraklUpdateTime);
        try {
            $this->stripePayoutRepository->persistAndFlush($newlyCreatedPayout);
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->info('Stripe Payout already exists but not created on Stripe', [
                'miraklInvoice' => $miraklInvoice,
            ]);
        }
        // Id can never be null after persist
        assert(null !== $newlyCreatedPayout->getId());
        $message = new ProcessPayoutMessage($newlyCreatedPayout->getId());
        $this->bus->dispatch($message);
    }

    private function getAmount(array $miraklInvoice, string $amountKey)
    {
        $amount = (int) abs(100 * $miraklInvoice['summary'][$amountKey]);
        if (0 === $amount) {
            $this->logger->info('Transfer amount is 0. Nothing to dispatch', [
                'mirakl_order' => $miraklInvoice,
                'key' => $amountKey,
            ]);

            throw new UndispatchableException('Transfer amount is 0. Nothing to dispatch');
        }

        return $amount;
    }

    private function getMiraklStripeMapping(array $miraklInvoice)
    {
        $miraklStripeMapping = $this->miraklStripeMappingRepository->findOneBy([
            'miraklShopId' => $miraklInvoice['shop_id'],
        ]);

        return $miraklStripeMapping;
    }

    private function getMiraklUpdateTime(array $miraklInvoice)
    {
        $miraklUpdateTime = \DateTime::createFromFormat(MiraklClient::DATE_FORMAT, $miraklInvoice['end_time']);
        if (!$miraklUpdateTime) {
            $this->logger->error('Cannot parse last_updated_date from Mirakl', ['mirakl_order' => $miraklInvoice]);

            throw new UndispatchableException('Cannot parse last_updated_date from Mirakl');
        }

        return $miraklUpdateTime;
    }
}
