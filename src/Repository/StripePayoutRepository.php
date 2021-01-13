<?php

namespace App\Repository;

use App\Entity\StripePayout;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method StripePayout|null find($id, $lockMode = null, $lockVersion = null)
 * @method StripePayout|null findOneBy(array $criteria, array $orderBy = null)
 * @method StripePayout[]    findAll()
 * @method StripePayout[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class StripePayoutRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StripePayout::class);
    }

    public function persistAndFlush(StripePayout $stripePayout): StripePayout
    {
        $this->getEntityManager()->persist($stripePayout);
        $this->getEntityManager()->flush();

        return $stripePayout;
    }

    public function persist(StripePayout $stripePayout): StripePayout
    {
        $this->getEntityManager()->persist($stripePayout);

        return $stripePayout;
    }

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    private function mapPayoutsByInvoiceId(array $payouts)
    {
        $map = [];
        foreach ($payouts as $payout) {
            $map[$payout->getMiraklInvoiceId()] = $payout;
        }

        return $map;
    }

    public function findRetriablePayouts()
    {
        return $this->mapPayoutsByInvoiceId($this->findBy([
            'status' => StripePayout::getRetriableStatus()
        ]));
    }

    public function findPayoutsByInvoiceIds(array $invoiceIds)
    {
        return $this->mapPayoutsByInvoiceId($this->findBy([
            'miraklInvoiceId' => $invoiceIds
        ]));
    }
}
