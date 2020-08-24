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

    public function getLastMiraklUpdateTime(): ?\DateTimeInterface
    {
        $lastUpdatedStripePayout = $this->createQueryBuilder('p')
            ->orderBy('p.miraklUpdateTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (null === $lastUpdatedStripePayout) {
            return null;
        }

        return $lastUpdatedStripePayout->getMiraklUpdateTime();
    }

    public function findExistingPayoutsByInvoiceIds($idsToCheck)
    {
        $existingPayouts = $this->findBy([
            'miraklInvoiceId' => $idsToCheck
        ]);

        $payoutsByInvoiceId = [];
        foreach ($existingPayouts as $payout) {
            $payoutsByInvoiceId[$payout->getMiraklInvoiceId()] = $payout;
        }

        return $payoutsByInvoiceId;
    }
}
