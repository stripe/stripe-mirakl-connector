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

    public function getLastMiraklUpdateTime(): ?\DateTimeInterface
    {
        $lastUpdatedStripeTransfer = $this->createQueryBuilder('t')
            ->orderBy('t.miraklUpdateTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $lastUpdatedStripeTransfer) {
            return null;
        }

        return $lastUpdatedStripeTransfer->getMiraklUpdateTime();
    }

    protected function failTransfersBeforeQueryBuilder(\DateTimeInterface $before): QueryBuilder
    {
        return  $this->createQueryBuilder('t')
            ->where("t.miraklUpdateTime < :before")
            ->andWhere("t.status like :status")
            ->setParameter("before", $before)
            ->setParameter("status", StripeTransfer::TRANSFER_FAILED);
    }

    public function getFailTransfersBefore(\DateTimeInterface $before): array
    {
        $failTransfersQuery = $this->failTransfersBeforeQueryBuilder($before)->getQuery();

        return $failTransfersQuery->execute();
    }

    public function getMiraklOrderIdFailTransfersBefore(\DateTimeInterface $before)
    {
        $failTransfers = $this->getFailTransfersBefore($before);

        $miraklOrderId = [];
        foreach ($failTransfers as $transfer) {
            $miraklOrderId[] = $transfer->getMiraklId();
        }

        return $miraklOrderId;
    }

    public function findExistingTransfersByOrderIds($idsToCheck)
    {
        $existingTransfers = $this->findBy([
            'miraklId' => $idsToCheck,
            'type' => StripeTransfer::TRANSFER_ORDER,
        ]);

        $transfersByOrderId = [];
        foreach ($existingTransfers as $transfer) {
            $transfersByOrderId[$transfer->getMiraklId()] = $transfer;
        }

        return $transfersByOrderId;
    }

    public function findExistingTransfersByInvoiceIds($idsToCheck)
    {
        $existingTransfers = $this->findBy([
            'miraklId' => $idsToCheck,
            'type' => [
                StripeTransfer::TRANSFER_SUBSCRIPTION,
                StripeTransfer::TRANSFER_EXTRA_CREDITS,
                StripeTransfer::TRANSFER_EXTRA_INVOICES
            ]
        ]);

        $transfersByInvoiceIdAndType = [];
        foreach ($existingTransfers as $transfer) {
            $transfersByInvoiceIdAndType[$transfer->getMiraklId()] = [
                $transfer->getType() => $transfer
            ];
        }

        return $transfersByInvoiceIdAndType;
    }
}
