<?php

namespace App\Repository;

use App\Entity\StripeRefund;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripeRefund|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripeRefund|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripeRefund[]    findAll()
 * @method StripeRefund[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripeRefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeRefund::class);
    }

    public function persistAndFlush(StripeRefund $stripeRefund): StripeRefund
    {
        $this->getEntityManager()->persist($stripeRefund);
        $this->getEntityManager()->flush();

        return $stripeRefund;
    }

    public function persist(StripeRefund $stripeRefund): StripeRefund
    {
        $this->getEntityManager()->persist($stripeRefund);

        return $stripeRefund;
    }

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    private function mapRefundsByRefundId(array $refunds)
    {
        $map = [];
        foreach ($refunds as $refund) {
            $map[$refund->getMiraklRefundId()] = $refund;
        }

        return $map;
    }

    public function findRefundsByRefundIds(array $refundIds)
    {
        return $this->mapRefundsByRefundId($this->findBy([
            'miraklRefundId' => $refundIds
        ]));
    }
}
