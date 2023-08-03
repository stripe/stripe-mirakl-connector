<?php

namespace App\Repository;

use App\Entity\StripeTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
