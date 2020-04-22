<?php

namespace App\Repository;

use App\Entity\OnboardingAccount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @method OnboardingAccount|null find($id, $lockMode = null, $lockVersion = null)
 * @method OnboardingAccount|null findOneBy(array $criteria, array $orderBy = null)
 * @method OnboardingAccount[]    findAll()
 * @method OnboardingAccount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class OnboardingAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OnboardingAccount::class);
    }

    public function createOnboardingAccount(int $miraklShopId): OnboardingAccount
    {
        $onboardingAccount = new OnboardingAccount();
        $onboardingAccount
            ->setMiraklShopId($miraklShopId)
            ->setStripeState(bin2hex(random_bytes(16)));
        $entityManager = $this->getEntityManager();

        $entityManager->persist($onboardingAccount);
        $entityManager->flush();

        return $onboardingAccount;
    }

    public function deleteAndFlush(OnboardingAccount $toDelete)
    {
        $this->getEntityManager()->remove($toDelete);
        $this->getEntityManager()->flush();
    }
}
