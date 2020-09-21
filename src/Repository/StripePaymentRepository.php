<?php

namespace App\Repository;

use App\Entity\StripePayment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripePayment|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripePayment|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripePayment[]    findAll()
 * @method StripePayment[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripePaymentRepository extends ServiceEntityRepository
{
    /**
     * StripePaymentRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripePayment::class);
    }

    /**
     * @param StripePayment $stripePayment
     * @return StripePayment
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function persistAndFlush(StripePayment $stripePayment): StripePayment
    {
        $this->getEntityManager()->persist($stripePayment);
        $this->getEntityManager()->flush();

        return $stripePayment;
    }

    /**
     * @param StripePayment $stripePayment
     * @return StripePayment
     * @throws \Doctrine\ORM\ORMException
     */
    public function persist(StripePayment $stripePayment): StripePayment
    {
        $this->getEntityManager()->persist($stripePayment);

        return $stripePayment;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function flush()
    {
        $this->getEntityManager()->flush();
    }
}
