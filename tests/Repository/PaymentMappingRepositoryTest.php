<?php

namespace App\Tests\Repository;

use App\Entity\PaymentMapping;
use App\Repository\PaymentMappingRepository;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for PaymentMappingRepository, focused on the security fixes introduced to
 * prevent duplicate commercial-order mappings and to detect cross-status conflicts
 * in mapByMiraklCommercialOrderId().
 */
class PaymentMappingRepositoryTest extends KernelTestCase
{
    use RecreateDatabaseTrait;

    private PaymentMappingRepository $repo;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repo = static::getContainer()->get('doctrine')->getRepository(PaymentMapping::class);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function createMapping(
        string $orderId,
        string $chargeId,
        string $status = PaymentMapping::TO_CAPTURE,
        int $amount = 100
    ): PaymentMapping {
        $m = new PaymentMapping();
        $m->setMiraklCommercialOrderId($orderId);
        $m->setStripeChargeId($chargeId);
        $m->setStripeAmount($amount);
        $m->setStatus($status);
        $this->repo->persist($m);
        $this->repo->flush();
        return $m;
    }

    // -----------------------------------------------------------------------
    // persistIfCommercialOrderIsUnmapped
    // -----------------------------------------------------------------------

    public function testPersistIfCommercialOrderIsUnmappedCreatesMapping(): void
    {
        $mapping = new PaymentMapping();
        $mapping
            ->setMiraklCommercialOrderId('order_new')
            ->setStripeChargeId('ch_new')
            ->setStripeAmount(100);

        $existingMapping = $this->repo->persistIfCommercialOrderIsUnmapped($mapping);

        $this->assertNull($existingMapping);

        $persistedMapping = $this->repo->findOneByStripeChargeId('ch_new');
        $this->assertNotNull($persistedMapping);
        $this->assertSame('order_new', $persistedMapping->getMiraklCommercialOrderId());
    }

    public function testPersistIfCommercialOrderIsUnmappedReturnsExistingMappingWithoutPersistingCandidate(): void
    {
        $existingMapping = $this->createMapping('order_existing', 'ch_existing');

        $candidateMapping = new PaymentMapping();
        $candidateMapping
            ->setMiraklCommercialOrderId('order_existing')
            ->setStripeChargeId('ch_candidate')
            ->setStripeAmount(200);

        $result = $this->repo->persistIfCommercialOrderIsUnmapped($candidateMapping);

        $this->assertNotNull($result);
        $this->assertSame($existingMapping->getId(), $result->getId());
        $this->assertNull($this->repo->findOneByStripeChargeId('ch_candidate'));
    }

    public function testPersistIfCommercialOrderIsUnmappedRequiresCommercialOrderId(): void
    {
        $mapping = new PaymentMapping();
        $mapping
            ->setStripeChargeId('ch_without_order')
            ->setStripeAmount(100);

        $this->expectException(\InvalidArgumentException::class);

        $this->repo->persistIfCommercialOrderIsUnmapped($mapping);
    }

    // -----------------------------------------------------------------------
    // findOneByMiraklCommercialOrderId
    // -----------------------------------------------------------------------

    /**
     * The new lookup method must find an existing mapping by commercial order ID.
     */
    public function testFindOneByMiraklCommercialOrderIdReturnsExistingMapping(): void
    {
        $this->createMapping('order_alpha', 'ch_alpha');

        $result = $this->repo->findOneByMiraklCommercialOrderId('order_alpha');

        $this->assertNotNull($result);
        $this->assertEquals('ch_alpha', $result->getStripeChargeId());
        $this->assertEquals('order_alpha', $result->getMiraklCommercialOrderId());
    }

    /**
     * Must return null when no mapping exists for the given commercial order ID.
     */
    public function testFindOneByMiraklCommercialOrderIdReturnsNullForUnknownOrder(): void
    {
        $result = $this->repo->findOneByMiraklCommercialOrderId('order_does_not_exist');

        $this->assertNull($result);
    }

    /**
     * When two mappings exist for the same commercial order ID (a duplicate that
     * slipped through before the guard was added), the method returns one of them
     * rather than throwing — the controller's guard only needs to know "any exists".
     */
    public function testFindOneByMiraklCommercialOrderIdReturnsSomeMappingWhenDuplicateExists(): void
    {
        $this->createMapping('order_dup', 'ch_dup_first');
        $this->createMapping('order_dup', 'ch_dup_second');

        $result = $this->repo->findOneByMiraklCommercialOrderId('order_dup');

        $this->assertNotNull($result);
        $this->assertEquals('order_dup', $result->getMiraklCommercialOrderId());
    }

    // -----------------------------------------------------------------------
    // findToCapturePayments — cross-status duplicate detection
    // -----------------------------------------------------------------------

    /**
     * A single, non-conflicted TO_CAPTURE mapping must be returned normally.
     */
    public function testFindToCapturePaymentsReturnsNonConflictedOrder(): void
    {
        $this->createMapping('order_clean', 'ch_clean', PaymentMapping::TO_CAPTURE);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayHasKey('order_clean', $result);
        $this->assertEquals('ch_clean', $result['order_clean']->getStripeChargeId());
    }

    /**
     * Two different TO_CAPTURE orders must both appear when neither is conflicted.
     */
    public function testFindToCapturePaymentsReturnsAllNonConflictedOrders(): void
    {
        $this->createMapping('order_one', 'ch_one', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_two', 'ch_two', PaymentMapping::TO_CAPTURE);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayHasKey('order_one', $result);
        $this->assertArrayHasKey('order_two', $result);
    }

    /**
     * When two rows share the same commercial order ID and the SAME status (both
     * TO_CAPTURE), the order is conflicted and must be excluded from results.
     * This guards against two concurrent webhook deliveries both inserting a row
     * on platforms where the advisory lock or unique index is absent.
     */
    public function testFindToCapturePaymentsExcludesSameStatusDuplicates(): void
    {
        $this->createMapping('order_same_status_dup', 'ch_first', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_same_status_dup', 'ch_second', PaymentMapping::TO_CAPTURE);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayNotHasKey('order_same_status_dup', $result);
    }

    /**
     * When two rows share the same commercial order ID but have DIFFERENT statuses
     * (one TO_CAPTURE, one CAPTURED), the cross-status duplicate detection must
     * still flag the conflict and exclude the order.
     *
     * This is the critical case: the initial status-filtered query only returns the
     * TO_CAPTURE row, but the cross-status re-query reveals the second (CAPTURED)
     * row, making the total count 2 and triggering exclusion.
     */
    public function testFindToCapturePaymentsExcludesCrossStatusDuplicates(): void
    {
        $this->createMapping('order_cross_dup', 'ch_to_capture', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_cross_dup', 'ch_captured',   PaymentMapping::CAPTURED);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayNotHasKey('order_cross_dup', $result);
    }

    /**
     * Cross-status detection must also work when the hidden duplicate has status
     * CAPTURE_FAILED (also part of the findToCapturePayments filter), confirming
     * the de-duplication is not accidentally avoided because both rows are in scope.
     */
    public function testFindToCapturePaymentsExcludesDuplicatesAcrossAllCaptureStatuses(): void
    {
        $this->createMapping('order_multi_status', 'ch_to_capture',   PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_multi_status', 'ch_capture_failed', PaymentMapping::CAPTURE_FAILED);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayNotHasKey('order_multi_status', $result);
    }

    /**
     * A clean order must remain accessible even when another order in the same
     * result set is conflicted and excluded.
     */
    public function testFindToCapturePaymentsPreservesCleanOrdersWhenOtherOrderIsConflicted(): void
    {
        // Conflicted order.
        $this->createMapping('order_conflict', 'ch_dup_a', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_conflict', 'ch_dup_b', PaymentMapping::CAPTURED);

        // Clean, non-conflicted order.
        $this->createMapping('order_legit', 'ch_legit', PaymentMapping::TO_CAPTURE);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayNotHasKey('order_conflict', $result);
        $this->assertArrayHasKey('order_legit', $result);
        $this->assertEquals('ch_legit', $result['order_legit']->getStripeChargeId());
    }

    /**
     * A CAPTURE_FAILED mapping with no duplicate must appear in the results (it is
     * included in the TO_CAPTURE query status filter for retry logic).
     */
    public function testFindToCapturePaymentsIncludesCaptureFailed(): void
    {
        $this->createMapping('order_retry', 'ch_retry', PaymentMapping::CAPTURE_FAILED);

        $result = $this->repo->findToCapturePayments();

        $this->assertArrayHasKey('order_retry', $result);
    }

    // -----------------------------------------------------------------------
    // findPaymentsByCommercialOrderIdsAndStatuses — cross-status detection
    // -----------------------------------------------------------------------

    /**
     * When two CAPTURED rows exist for the same commercial order, the cross-status
     * check detects count > 1 and excludes the order from findPaymentsByCommercialOrderIdsAndStatuses.
     */
    public function testFindPaymentsByCommercialOrderIdsAndStatusesExcludesDuplicateCapturedRows(): void
    {
        $this->createMapping('order_dup_cap', 'ch_cap_a', PaymentMapping::CAPTURED);
        $this->createMapping('order_dup_cap', 'ch_cap_b', PaymentMapping::CAPTURED);

        $result = $this->repo->findPaymentsByCommercialOrderIdsAndStatuses(
            ['order_dup_cap'],
            [PaymentMapping::CAPTURED]
        );

        $this->assertArrayNotHasKey('order_dup_cap', $result);
    }

    /**
     * A single CAPTURED mapping for a given commercial order must be returned
     * correctly by findPaymentsByCommercialOrderIdsAndStatuses.
     */
    public function testFindPaymentsByCommercialOrderIdsAndStatusesReturnsSingleCapturedRow(): void
    {
        $this->createMapping('order_single_cap', 'ch_single_cap', PaymentMapping::CAPTURED);

        $result = $this->repo->findPaymentsByCommercialOrderIdsAndStatuses(
            ['order_single_cap'],
            [PaymentMapping::CAPTURED]
        );

        $this->assertArrayHasKey('order_single_cap', $result);
        $this->assertEquals('ch_single_cap', $result['order_single_cap']->getStripeChargeId());
    }

    /**
     * Cross-status duplicate: one TO_CAPTURE row and one CAPTURED row for the same
     * commercial order. When querying only CAPTURED rows the TO_CAPTURE duplicate is
     * invisible to the initial query, but the cross-status re-fetch reveals it and
     * the order is excluded.
     */
    public function testFindPaymentsByCommercialOrderIdsAndStatusesDetectsCrossStatusDuplicate(): void
    {
        $this->createMapping('order_mixed', 'ch_mixed_cap',  PaymentMapping::CAPTURED);
        $this->createMapping('order_mixed', 'ch_mixed_tocp', PaymentMapping::TO_CAPTURE);

        $result = $this->repo->findPaymentsByCommercialOrderIdsAndStatuses(
            ['order_mixed'],
            [PaymentMapping::CAPTURED]
        );

        $this->assertArrayNotHasKey('order_mixed', $result);
    }

    // -----------------------------------------------------------------------
    // findPaymentsByCommercialOrderIds
    // -----------------------------------------------------------------------

    /**
     * findPaymentsByCommercialOrderIds must include non-conflicted orders of any status.
     */
    public function testFindPaymentsByCommercialOrderIdsReturnsNonConflictedMapping(): void
    {
        $this->createMapping('order_any_status', 'ch_any', PaymentMapping::CANCELED);

        $result = $this->repo->findPaymentsByCommercialOrderIds(['order_any_status']);

        $this->assertArrayHasKey('order_any_status', $result);
        $this->assertEquals('ch_any', $result['order_any_status']->getStripeChargeId());
    }

    /**
     * findPaymentsByCommercialOrderIds must exclude conflicted commercial orders
     * (two rows, any status combination).
     */
    public function testFindPaymentsByCommercialOrderIdsExcludesDuplicates(): void
    {
        $this->createMapping('order_full_dup', 'ch_fd_a', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_full_dup', 'ch_fd_b', PaymentMapping::CANCELED);

        $result = $this->repo->findPaymentsByCommercialOrderIds(['order_full_dup']);

        $this->assertArrayNotHasKey('order_full_dup', $result);
    }

    /**
     * When queried with multiple commercial order IDs, only the non-conflicted
     * ones appear in the result.
     */
    public function testFindPaymentsByCommercialOrderIdsMixedCleanAndConflicted(): void
    {
        // Clean
        $this->createMapping('order_good', 'ch_good', PaymentMapping::CAPTURED);
        // Conflicted
        $this->createMapping('order_bad', 'ch_bad_1', PaymentMapping::TO_CAPTURE);
        $this->createMapping('order_bad', 'ch_bad_2', PaymentMapping::CAPTURED);

        $result = $this->repo->findPaymentsByCommercialOrderIds(['order_good', 'order_bad']);

        $this->assertArrayHasKey('order_good', $result);
        $this->assertArrayNotHasKey('order_bad', $result);
    }
}
