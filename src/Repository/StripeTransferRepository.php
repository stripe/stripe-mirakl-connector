<?php

namespace App\Repository;

use App\Entity\StripeTransfer;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    public function findAlreadyCreatedMiraklIds($idsToCheck)
    {
        $existingIds = $this->findBy([
            'miraklId' => $idsToCheck,
            'status' => StripeTransfer::TRANSFER_CREATED,
        ]);

        return array_map(
            function ($transfer) {
                return $transfer->getMiraklId();
            },
            $existingIds
        );
    }
}
