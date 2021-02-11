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

    public function persist(PaymentMapping $paymentMapping): PaymentMapping
    {
        $this->getEntityManager()->persist($paymentMapping);
        return $paymentMapping;
    }

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    /**
     * @param array $paymentMappings
     * @return array
     */
    private function mapByMiraklCommercialOrderId(array $paymentMappings): array
    {
        $map = [];
        foreach ($paymentMappings as $paymentMapping) {
            $map[$paymentMapping->getMiraklCommercialOrderId()] = $paymentMapping;
        }

        return $map;
    }

    /**
     * @return PaymentMapping[]
     */
    public function findToCapturePayments(): array
    {
        return $this->mapByMiraklCommercialOrderId($this->findBy([
            'status' => PaymentMapping::TO_CAPTURE
        ]));
    }

    /**
     * @param array $commercialOrderIds
     * @return PaymentMapping[]
     */
    public function findPaymentsByCommercialOrderIds(array $commercialOrderIds): array
    {
        return $this->mapByMiraklCommercialOrderId($this->findBy([
            'miraklCommercialOrderId' => $commercialOrderIds
        ]));
    }

    public function findOneByStripeChargeId(string $stripeChargeId): ?PaymentMapping
    {
        return $this->findOneBy([
            'stripeChargeId' => $stripeChargeId
        ]);
    }
}
