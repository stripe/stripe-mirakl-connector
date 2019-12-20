<?php

namespace App\Repository;

use App\Entity\MiraklStripeMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Common\Persistence\ManagerRegistry;

/**
 * @method MiraklStripeMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method MiraklStripeMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method MiraklStripeMapping[]    findAll()
 * @method MiraklStripeMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MiraklStripeMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MiraklStripeMapping::class);
    }

    public function persistAndFlush(MiraklStripeMapping $miraklStripeMapping): MiraklStripeMapping
    {
        $this->getEntityManager()->persist($miraklStripeMapping);
        $this->getEntityManager()->flush();

        return $miraklStripeMapping;
    }
}
