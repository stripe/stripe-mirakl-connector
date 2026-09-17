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

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    /**
     * Persists a mapping when no mapping exists for its commercial order.
     *
     * Returns the existing mapping when the commercial order is already mapped;
     * otherwise returns null after creating the supplied mapping.
     */
    public function persistIfCommercialOrderIsUnmapped(PaymentMapping $paymentMapping): ?PaymentMapping
    {
        $commercialOrderId = $paymentMapping->getMiraklCommercialOrderId();
        if (null === $commercialOrderId) {
            throw new \InvalidArgumentException('A commercial order ID is required to create a payment mapping.');
        }

        $connection = $this->getEntityManager()->getConnection();
        $connection->beginTransaction();

        try {
            if ($connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                $connection->executeStatement(
                    'SELECT pg_advisory_xact_lock(hashtextextended(:id, 0))',
                    ['id' => $commercialOrderId]
                );
            }

            $existingMapping = $this->findOneByMiraklCommercialOrderId($commercialOrderId);
            if (null !== $existingMapping) {
                $connection->rollBack();

                return $existingMapping;
            }

            $this->getEntityManager()->persist($paymentMapping);
            $this->getEntityManager()->flush();
            $connection->commit();

            return null;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }

    private function mapByMiraklCommercialOrderId(array $paymentMappings): array
    {
        if (empty($paymentMappings)) {
            return [];
        }

        // Collect the commercial order IDs visible in the (possibly status-filtered) input.
        $commercialOrderIds = array_values(array_unique(array_filter(
            array_map(static fn($pm) => $pm->getMiraklCommercialOrderId(), $paymentMappings)
        )));

        if (empty($commercialOrderIds)) {
            return [];
        }

        // Query ALL rows for those commercial order IDs, ignoring any status filter the
        // caller applied. A status-filtered query (e.g. findToCapturePayments) might show
        // only one of two duplicate rows; the hidden duplicate would not be detected unless
        // we count across every status here.
        $conflicted = [];
        $countPerOrder = [];
        foreach ($this->findBy(['miraklCommercialOrderId' => $commercialOrderIds]) as $row) {
            $id = $row->getMiraklCommercialOrderId();
            $countPerOrder[$id] = ($countPerOrder[$id] ?? 0) + 1;
            if ($countPerOrder[$id] > 1) {
                $conflicted[$id] = true;
            }
        }

        // Build the result map, excluding any commercial order that has duplicate rows in
        // any status. Exclusion is safer than guessing which row is legitimate.
        $map = [];
        foreach ($paymentMappings as $paymentMapping) {
            $commercialId = $paymentMapping->getMiraklCommercialOrderId();
            if (!isset($conflicted[$commercialId])) {
                $map[$commercialId] = $paymentMapping;
            }
        }

        return $map;
    }

    /**
     * @return PaymentMapping[]
     */
    public function findToCapturePayments(): array
    {
        return $this->mapByMiraklCommercialOrderId($this->findBy([
            'status' => [
                PaymentMapping::TO_CAPTURE,
                PaymentMapping::CAPTURE_FAILED,
                PaymentMapping::CANCEL_FAILED,
            ],
        ]));
    }

    /**
     * @return PaymentMapping[]
     */
    public function findPaymentsByCommercialOrderIds(array $commercialOrderIds): array
    {
        return $this->mapByMiraklCommercialOrderId($this->findBy([
            'miraklCommercialOrderId' => $commercialOrderIds,
        ]));
    }

    /**
     * @return PaymentMapping[]
     */
    public function findPaymentsByCommercialOrderIdsAndStatuses(array $commercialOrderIds, array $status): array
    {
        return $this->mapByMiraklCommercialOrderId($this->findBy([
            'miraklCommercialOrderId' => $commercialOrderIds,
            'status' => $status,
        ]));
    }

    public function findOneByStripeChargeId(string $stripeChargeId): ?PaymentMapping
    {
        return $this->findOneBy([
            'stripeChargeId' => $stripeChargeId,
        ]);
    }

    public function findOneByMiraklCommercialOrderId(string $commercialOrderId): ?PaymentMapping
    {
        return $this->findOneBy([
            'miraklCommercialOrderId' => $commercialOrderId,
        ]);
    }
}
