<?php

namespace App\Repository;

use App\Entity\AccountOnboarding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method AccountOnboarding|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccountOnboarding|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccountOnboarding[]    findAll()
 * @method AccountOnboarding[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountOnboardingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountOnboarding::class);
    }

    public function createAccountOnboarding(int $miraklShopId): AccountOnboarding
    {
        $accountOnboarding = new AccountOnboarding();
        $accountOnboarding
            ->setMiraklShopId($miraklShopId)
            ->setStripeState(bin2hex(random_bytes(16)));
        $entityManager = $this->getEntityManager();

        $entityManager->persist($accountOnboarding);
        $entityManager->flush();

        return $accountOnboarding;
    }

    public function deleteAndFlush(AccountOnboarding $toDelete)
    {
        $this->getEntityManager()->remove($toDelete);
        $this->getEntityManager()->flush();
    }

    public function findOneByStripeState(string $state): ?AccountOnboarding
    {
        return $this->findOneBy([
            'stripeState' => $state
        ]);
    }
}
