<?php

namespace App\Repository;

use App\Entity\StripeTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    private function mapTransfersByMiraklId(array $transfers)
    {
        $map = [];
        foreach ($transfers as $transfer) {
            $map[$transfer->getMiraklId()] = $transfer;
        }

        return $map;
    }

    private function mapTransfersByMiraklIdAndType(array $transfers)
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

    public function findTransfersByOrderIds(array $orderIds)
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::getOrderTypes(),
            'miraklId' => $orderIds
        ]));
    }

    public function findTransfersByRefundIds(array $refundIds)
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'miraklId' => $refundIds
        ]));
    }

    public function findRetriableProductOrderTransfers()
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_PRODUCT_ORDER,
            'status' => StripeTransfer::getRetriableStatus()
        ]));
    }

    public function findRetriableServiceOrderTransfers()
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_SERVICE_ORDER,
            'status' => StripeTransfer::getRetriableStatus()
        ]));
    }

    public function findRetriableRefundTransfers()
    {
        return $this->mapTransfersByMiraklId($this->findBy([
            'type' => StripeTransfer::TRANSFER_REFUND,
            'status' => StripeTransfer::getRetriableStatus()
        ]));
    }

    public function findRetriableInvoiceTransfers()
    {
        return $this->mapTransfersByMiraklIdAndType($this->findBy([
            'type' => StripeTransfer::getInvoiceTypes(),
            'status' => StripeTransfer::getRetriableStatus()
        ]));
    }

    public function findTransfersByInvoiceIds(array $invoiceIds)
    {
        return $this->mapTransfersByMiraklIdAndType($this->findBy([
            'type' => StripeTransfer::getInvoiceTypes(),
            'miraklId' => $invoiceIds
        ]));
    }
}
