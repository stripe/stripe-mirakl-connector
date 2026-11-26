<?php

namespace App\Repository;

use App\Entity\StripeTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripeTransfer|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripeTransfer|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripeTransfer[]    findAll()
 * @method StripeTransfer[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripeTransferRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeTransfer::class);
    }

    public function persistAndFlush(StripeTransfer $stripeTransfer): StripeTransfer
    {
        $this->persist($stripeTransfer);
        $this->flush();

        return $stripeTransfer;
    }

    public function persist(StripeTransfer $stripeTransfer): StripeTransfer
    {
        $this->getEntityManager()->persist($stripeTransfer);

        return $stripeTransfer;
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    public function wrapInTransaction(callable $operation): mixed
    {
        return $this->getEntityManager()->wrapInTransaction($operation);
    }

    private function mapTransfersByMiraklId(array $transfers): array
    {
        $map = [];
        foreach ($transfers as $transfer) {
            $map[$transfer->getMiraklId()] = $transfer;
        }

        return $map;
    }

    private function mapTransfersByMiraklIdAndType(array $transfers): array
    {
        $map = [];
        foreach ($transfers as $transfer) {
            if (!isset($map[$transfer->getMiraklId()])) {
                $map[$transfer->getMiraklId()] = [];
            }

            $map[$transfer->getMiraklId()][$transfer->getType()] = $transfer;
        }

        return $map;
    }

    public function findTransfersByOrderIds(array $orderIds): array
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::getOrderTypes(),
            'miraklId' => $orderIds,
        ]));
    }

    public function findTransfersByRefundIds(array $refundIds): array
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'miraklId' => $refundIds,
        ]));
    }

    public function getReservedRefundAmountForTransaction(string $transactionId, string $excludeMiraklId): int
    {
        $amount = 0;
        foreach ($this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'transactionId' => $transactionId,
            'status' => [StripeTransfer::TRANSFER_PENDING, StripeTransfer::TRANSFER_CREATED],
        ]) as $transfer) {
            if ($transfer->getMiraklId() !== $excludeMiraklId) {
                $amount += $transfer->getAmount() ?? 0;
            }
        }

        return $amount;
    }

    /**
     * Lock the original order transfers while refund reversals are reserved.
     * The caller must already be inside a transaction.
     */
    public function lockOrderTransfersByMiraklIds(array $miraklIds): void
    {
        if (!$miraklIds) {
            return;
        }

        $this->createQueryBuilder('transfer')
            ->where('transfer.type IN (:types)')
            ->andWhere('transfer.miraklId IN (:miraklIds)')
            ->setParameter('types', StripeTransfer::getOrderTypes())
            ->setParameter('miraklIds', $miraklIds)
            ->orderBy('transfer.id', 'ASC')
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    public function findRetriableProductOrderTransfers(): array
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_PRODUCT_ORDER,
            'status' => StripeTransfer::getRetriableStatus(),
        ]));
    }

    public function findRetriableServiceOrderTransfers(): array
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_SERVICE_ORDER,
            'status' => StripeTransfer::getRetriableStatus(),
        ]));
    }

    public function findRetriableRefundTransfers(): array
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'status' => StripeTransfer::getRetriableStatus(),
        ]));
    }

    /**
     * Refund reversals in these states are needed to reconcile tax shortfalls:
     * completed component reversals and pending shortfall reversals awaiting dispatch.
     *
     * @return StripeTransfer[]
     */
    public function findRefundTransfersForTaxShortfallReconciliation(): array
    {
        return $this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'status' => [StripeTransfer::TRANSFER_CREATED, StripeTransfer::TRANSFER_PENDING],
        ]);
    }

    public function findRetriableInvoiceTransfers(): array
    {
        return $this->mapTransfersByMiraklIdAndType($this->findBy([
            'type' => StripeTransfer::getInvoiceTypes(),
            'status' => StripeTransfer::getRetriableStatus(),
        ]));
    }

    public function findTransfersByInvoiceIds(array $invoiceIds): array
    {
        return $this->mapTransfersByMiraklIdAndType($this->findBy([
            'type' => StripeTransfer::getInvoiceTypes(),
            'miraklId' => $invoiceIds,
        ]));
    }
}
