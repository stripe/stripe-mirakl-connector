<?php

namespace App\Repository;

use App\Entity\PaymentMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method PaymentMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaymentMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaymentMapping[]    findAll()
 * @method PaymentMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentMappingRepository extends ServiceEntityRepository
{
    /**
     * PaymentMappingRepository constructor.
     * @param ManagerRegistry $registry
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentMapping::class);
    }

    /**
     * @param PaymentMapping $paymentMapping
     * @return PaymentMapping
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    public function persistAndFlush(PaymentMapping $paymentMapping): PaymentMapping
    {
        $this->getEntityManager()->persist($paymentMapping);
        $this->getEntityManager()->flush();

        return $paymentMapping;
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
     * @param array $paymentMappings
     * @return array
     */
    private function mapByMiraklOrderId(array $paymentMappings): array
    {
        $map = [];
        foreach ($paymentMappings as $paymentMapping) {
            $map[$paymentMapping->getMiraklOrderId()] = $paymentMapping;
        }

        return $map;
    }

    /**
     * @return PaymentMapping[]
     */
    public function findToCapturePayments(): array
    {
        return $this->mapByMiraklOrderId($this->findBy([
            'status' => PaymentMapping::TO_CAPTURE
        ]));
    }

    /**
     * @param array $orderIds
     * @return PaymentMapping[]
     */
    public function findPaymentsByOrderIds(array $orderIds): array
    {
        return $this->mapByMiraklOrderId($this->findBy([
            'miraklOrderId' => $orderIds
        ]));
    }

    public function findOneByStripeChargeId(string $stripeChargeId): ?PaymentMapping
    {
        return $this->findOneBy([
            'stripeChargeId' => $stripeChargeId
        ]);
    }
}
