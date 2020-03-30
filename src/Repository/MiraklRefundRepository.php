<?php

namespace App\Repository;

use App\Entity\MiraklRefund;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method MiraklRefund|null find($id, $lockMode = null, $lockVersion = null)
 * @method MiraklRefund|null findOneBy(array $criteria, array $orderBy = null)
 * @method MiraklRefund[]    findAll()
 * @method MiraklRefund[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MiraklRefundRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiraklRefund::class);
    }

    public function persistAndFlush(MiraklRefund $miraklRefund): MiraklRefund
    {
        $this->getEntityManager()->persist($miraklRefund);
        $this->getEntityManager()->flush();

        $this->getEntityManager()->detach($miraklRefund);

        return $miraklRefund;
    }

    public function persist(MiraklRefund $refund): MiraklRefund
    {
        $this->getEntityManager()->persist($refund);

        return $refund;
    }

}
