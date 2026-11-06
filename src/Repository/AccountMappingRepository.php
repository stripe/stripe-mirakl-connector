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

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function findOneByStripeAccountId(string $stripeAccountId): ?AccountMapping
    {
        return $this->findOneBy([
            'stripeAccountId' => $stripeAccountId,
        ]);
    }

    public function findOneByOnboardingToken(string $onboardingToken): ?AccountMapping
    {
        return $this->findOneBy([
            'onboardingToken' => $onboardingToken,
        ]);
    }

    /** @return AccountMapping[] */
    public function findOnboardingLinksDueForRenewal(): array
    {
        return $this->createQueryBuilder('mapping')
            ->where('mapping.onboardingTokenExpiresAt IS NOT NULL')
            ->andWhere('mapping.onboardingTokenExpiresAt <= :now')
            ->setParameter('now', new \DateTimeImmutable(), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomically consume a valid token, including when another request already loaded it.
     */
    public function consumeOnboardingToken(AccountMapping $accountMapping, string $token): bool
    {
        $entityManager = $this->getEntityManager();
        $updated = $entityManager->createQueryBuilder()
            ->update(AccountMapping::class, 'mapping')
            ->set('mapping.onboardingToken', ':empty')
            ->set('mapping.onboardingTokenExpiresAt', ':recoveryAt')
            ->where('mapping.id = :id')
            ->andWhere('mapping.onboardingToken = :token')
            ->andWhere('mapping.onboardingTokenExpiresAt > :now')
            ->setParameter('empty', null)
            ->setParameter('id', $accountMapping->getId())
            ->setParameter('token', $token)
            ->setParameter('now', new \DateTimeImmutable(), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('recoveryAt', new \DateTimeImmutable('+5 minutes'), \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->execute();

        if (1 !== $updated) {
            return false;
        }

        // Bulk updates bypass the ORM identity map. Synchronize its tracked state.
        $entityManager->refresh($accountMapping);

        return true;
    }

    public function restoreOnboardingToken(AccountMapping $accountMapping, string $token, \DateTimeImmutable $expiresAt): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->update(AccountMapping::class, 'mapping')
            ->set('mapping.onboardingToken', ':token')
            ->set('mapping.onboardingTokenExpiresAt', ':expiresAt')
            ->where('mapping.id = :id')
            ->andWhere('mapping.onboardingToken IS NULL')
            ->andWhere('mapping.onboardingTokenExpiresAt IS NOT NULL')
            ->setParameter('token', $token)
            ->setParameter('expiresAt', $expiresAt, \Doctrine\DBAL\Types\Types::DATETIME_IMMUTABLE)
            ->setParameter('id', $accountMapping->getId())
            ->getQuery()
            ->execute();

        $this->getEntityManager()->refresh($accountMapping);
    }

    private function mapByMiraklShopId(array $accountMappings): array
    {
        $map = [];
        foreach ($accountMappings as $accountMapping) {
            $map[$accountMapping->getMiraklShopId()] = $accountMapping;
        }

        return $map;
    }

    /**
     * @return AccountMapping[]
     */
    public function findByMiraklShopIds(array $miraklShopIds): array
    {
        return $this->mapByMiraklShopId($this->findBy([
            'miraklShopId' => $miraklShopIds,
        ]));
    }
}
