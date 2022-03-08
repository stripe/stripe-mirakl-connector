<?php

namespace App\Repository;

use App\Entity\AccountMapping;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method AccountMapping|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccountMapping|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccountMapping[]    findAll()
 * @method AccountMapping[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountMappingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountMapping::class);
    }

    public function removeAndFlush(AccountMapping $accountMapping): void
    {
        $this->getEntityManager()->remove($accountMapping);
        $this->getEntityManager()->flush();
    }

    public function persistAndFlush(AccountMapping $accountMapping): AccountMapping
    {
        $this->getEntityManager()->persist($accountMapping);
        $this->getEntityManager()->flush();

        return $accountMapping;
    }

    public function flush()
    {
        $this->getEntityManager()->flush();
    }

    public function findOneByStripeAccountId(string $stripeAccountId): ?AccountMapping
    {
        return $this->findOneBy([
            'stripeAccountId' => $stripeAccountId
        ]);
    }

    public function findOneByOnboardingToken(string $onboardingToken): ?AccountMapping
    {
        return $this->findOneBy([
            'onboardingToken' => $onboardingToken
        ]);
    }

    /**
     * @param array $accountMappings
     * @return array
     */
    private function mapByMiraklShopId(array $accountMappings): array
    {
        $map = [];
        foreach ($accountMappings as $accountMapping) {
            $map[$accountMapping->getMiraklShopId()] = $accountMapping;
        }

        return $map;
    }

    /**
     * @param array $miraklShopIds
     * @return AccountMapping[]
     */
    public function findByMiraklShopIds(array $miraklShopIds): array
    {
        return $this->mapByMiraklShopId($this->findBy([
            'miraklShopId' => $miraklShopIds
        ]));
    }
}
