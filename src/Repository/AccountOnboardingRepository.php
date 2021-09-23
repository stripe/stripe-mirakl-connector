<?php

namespace App\Repository;

use App\Entity\AccountOnboarding;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Helper\LoggerHelper;

/**
 * @method AccountOnboarding|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccountOnboarding|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccountOnboarding[]    findAll()
 * @method AccountOnboarding[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountOnboardingRepository extends ServiceEntityRepository
{
private $loggerHelper;

    public function __construct(ManagerRegistry $registry, LoggerHelper $loggerHelper)
    {
$this->loggerHelper = $loggerHelper;
        parent::__construct($registry, AccountOnboarding::class);
    }

    public function createAccountOnboarding(int $miraklShopId): AccountOnboarding
    {
$this->loggerHelper->getLogger()->info("Se va a intentar crear un Onboarding para la tienda ".$miraklShopId,['miraklShopId' => $miraklShopId]);
        $accountOnboarding = new AccountOnboarding();
        $accountOnboarding
            ->setMiraklShopId($miraklShopId)
            ->setStripeState(bin2hex(random_bytes(16)));
        $entityManager = $this->getEntityManager();

        $entityManager->persist($accountOnboarding);
        $entityManager->flush();

$accountOnboardingSecure = $this->findOneBy(['miraklShopId' => $miraklShopId]);
$varAccountOnboardingSecure = !empty($accountOnboardingSecure) ? $accountOnboardingSecure->getId() : 'ha fallado';
$this->loggerHelper->getLogger()->info("Se ha intentando crear un Onboarding para la tienda ".$miraklShopId,['miraklShopId' => $miraklShopId, 'extra'=> ['AccountOnboarding' => $varAccountOnboardingSecure]]);

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
