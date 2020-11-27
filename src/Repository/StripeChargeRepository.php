<?php

namespace App\Repository;

use App\Entity\StripeCharge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripeCharge|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripeCharge|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripeCharge[]    findAll()
 * @method StripeCharge[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripeChargeRepository extends ServiceEntityRepository
{
    /**
     * StripeChargeRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripeCharge::class);
    }

    /**
     * @param array $orderIds
     * @return StripeCharge[]
     */
    public function findPendingChargeByOrderIds(array $orderIds): array
    {
        $charges = $this->findBy([
            'miraklOrderId' => $orderIds,
            'status' => StripeCharge::TO_CAPTURE
        ]);

        return $this->orderByMiraklOrderId($charges);
    }

    /**
     * @return StripeCharge[]
     */
    public function findPendingPayments(): array
    {
        $charges = $this->findBy([
            'status' => StripeCharge::TO_CAPTURE
        ]);

        return $this->orderByMiraklOrderId($charges);
    }

    /**
     * @param StripeCharge $stripeCharge
     * @return StripeCharge
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function persistAndFlush(StripeCharge $stripeCharge): StripeCharge
    {
        $this->getEntityManager()->persist($stripeCharge);
        $this->getEntityManager()->flush();

        return $stripeCharge;
    }

    /**
     * @param StripeCharge $stripeCharge
     * @return StripeCharge
     * @throws \Doctrine\ORM\ORMException
     */
    public function persist(StripeCharge $stripeCharge): StripeCharge
    {
        $this->getEntityManager()->persist($stripeCharge);

        return $stripeCharge;
    }

    /**
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @param array $charges
     * @return array
     */
    protected function orderByMiraklOrderId(array $charges): array
    {
        $chargeByOrderId = [];
        foreach ($charges as $charge) {
            $chargeByOrderId[$charge->getMiraklOrderId()] = $charge;
        }

        return $chargeByOrderId;
    }
}
