<?php

namespace App\Tests\Controller;

use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Entity\StripePayout;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
use App\Repository\StripePayoutRepository;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Transport\InMemoryTransport;

class StripeWebhookEndpointTest extends WebTestCase
{
    use RecreateDatabaseTrait;

    /**
     * @var string
     */
    protected $paymentKey;

    /**
     * @var KernelBrowser
     */
    protected $client;

    /**
     * @var AccountMappingRepository
     */
    protected $accountMappingRepository;

    /**
     * @var PaymentMappingRepository
     */
    protected $paymentMappingRepository;

    /**
     * @var StripePayoutRepository
     */
    protected $stripePayoutRepository;

    /**
     * @var InMemoryTransport
     */
    protected $updateLoginLinkQueue;

    protected function setUp(): void
    {
        $this->client =  self::createClient();
        $this->accountMappingRepository = static::getContainer()->get('doctrine')->getRepository(AccountMapping::class);
        $this->paymentMappingRepository = static::getContainer()->get('doctrine')->getRepository(PaymentMapping::class);
        $this->stripePayoutRepository = static::getContainer()->get('doctrine')->getRepository(StripePayout::class);
        $this->updateLoginLinkQueue = static::getContainer()->get('messenger.transport.update_login_link');
        $this->paymentKey = static::getContainer()->getParameter('app.workflow.payment_metadata_commercial_order_id');
    }

    private function executeRequest(string $endpoint, string $payload, ?string $signature = null)
    {
        $this->client->request('POST', $endpoint, [], [], ['HTTP_STRIPE_SIGNATURE' => $signature], $payload);
        return $this->client->getResponse();
    }

    private function executeDeprecatedRequest(string $payload, ?string $signature = null)
    {
        $this->updateLoginLinkQueue->reset();
        return $this->executeRequest('/api/public/webhook', $payload, $signature);
    }

    private function executeSellersRequest(string $payload, ?string $signature = null)
    {
        $this->updateLoginLinkQueue->reset();
        return $this->executeRequest('/api/public/webhook/sellers', $payload, $signature);
    }

    private function executeOperatorRequest(string $payload, ?string $signature = null)
    {
        return $this->executeRequest('/api/public/webhook/operator', $payload, $signature);
    }

    private function mockAccountMapping(int $shopId, string $accountId, bool $payinsEnabled = false, bool $payoutsEnabled = false, ?string $disableReason = null)
    {
        $accountMapping = new AccountMapping();
        $accountMapping->setMiraklShopId($shopId);
        $accountMapping->setStripeAccountId($accountId);
        $accountMapping->setOnboardingToken('token');
        $accountMapping->setPayinEnabled($payinsEnabled);
        $accountMapping->setPayoutEnabled($payoutsEnabled);
        $accountMapping->setDisabledReason($disableReason);

        $this->accountMappingRepository->persistAndFlush($accountMapping);

        return $accountMapping;
    }

    private function mockPaymentMapping(string $orderId, string $chargeId, bool $captured = false, ?string $statusReason = null)
    {
        $paymentMapping = new PaymentMapping();
        $paymentMapping->setMiraklCommercialOrderId($orderId);
        $paymentMapping->setStripeChargeId($chargeId);
        $paymentMapping->setStatus($captured ? PaymentMapping::CAPTURED : PaymentMapping::TO_CAPTURE);
        $paymentMapping->setStatusReason($statusReason);

        $this->paymentMappingRepository->persist($paymentMapping);
        $this->paymentMappingRepository->flush();

        return $paymentMapping;
    }

    public function testDeprecatedEndpoint()
    {
        $response = $this->executeDeprecatedRequest('{}');
        $this->assertEquals('Unhandled event type', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());
    }

    public function testInvalidPayload()
    {
        $response = $this->executeSellersRequest('Invalid');
        $this->assertEquals('Invalid payload.', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());
    }

    public function testInvalidSignature()
    {
        $response = $this->executeSellersRequest('{}', 'Invalid');
        $this->assertEquals('Invalid signature.', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());
    }

    public function testUnhandledEventType()
    {
        $id = StripeMock::ACCOUNT_BASIC;
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "account.created",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "account",
                    "charges_enabled": true,
                    "payouts_enabled": true,
                    "details_submitted": false
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Unhandled event type', $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());
    }

    public function testAccountUpdatedUnknownId()
    {
        $id = StripeMock::ACCOUNT_NEW;
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "account.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "account",
                    "charges_enabled": true,
                    "payouts_enabled": true,
                    "details_submitted": false
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring account.updated event for non-Mirakl Stripe account.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());
    }

    public function testAccountUpdatedButNotSubmittedYet()
    {
        $id = StripeMock::ACCOUNT_NEW;
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, $id);
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "account.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "account",
                    "charges_enabled": true,
                    "payouts_enabled": true,
                    "requirements": {"disabled_reason": null},
                    "details_submitted": false
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring account.updated event until details are submitted for account.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(0, $this->updateLoginLinkQueue->getSent());

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($id);
        $this->assertEquals('token', $accountMapping->getOnboardingToken());
        $this->assertEquals(false, $accountMapping->getPayinEnabled());
        $this->assertEquals(false, $accountMapping->getPayoutEnabled());
        $this->assertNull($accountMapping->getDisabledReason());
    }

    public function testAccountUpdatedEnabled()
    {
        $id = StripeMock::ACCOUNT_NEW;
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, $id);
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "account.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "account",
                    "charges_enabled": true,
                    "payouts_enabled": true,
                    "requirements": {"disabled_reason": null},
                    "details_submitted": true
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Account mapping updated.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $this->updateLoginLinkQueue->getSent());

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($id);
        $this->assertNull($accountMapping->getOnboardingToken());
        $this->assertEquals(true, $accountMapping->getPayinEnabled());
        $this->assertEquals(true, $accountMapping->getPayoutEnabled());
        $this->assertNull($accountMapping->getDisabledReason());
    }

    public function testAccountUpdatedDisabled()
    {
        $id = StripeMock::ACCOUNT_NEW;
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, $id);
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "account.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "account",
                    "charges_enabled": false,
                    "payouts_enabled": false,
                    "requirements": {"disabled_reason": "Prohibited business"},
                    "details_submitted": true
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Account mapping updated.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertCount(1, $this->updateLoginLinkQueue->getSent());

        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId($id);
        $this->assertNull($accountMapping->getOnboardingToken());
        $this->assertEquals(false, $accountMapping->getPayinEnabled());
        $this->assertEquals(false, $accountMapping->getPayoutEnabled());
        $this->assertEquals("Prohibited business", $accountMapping->getDisabledReason());
    }

    public function testPaymentIntentCreated()
    {
        $id = StripeMock::PAYMENT_INTENT_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "payment_intent.created",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "payment_intent",
                    "metadata": {},
                    "status": "requires_payment_method"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('The event type payment_intent.created is no longer required and can be removed in the webhook settings.', (string) $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testChargeUpdatedNoMetadata()
    {
        $id = StripeMock::CHARGE_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "charge",
                    "metadata": {},
                    "status": "pending"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring event with no Mirakl Commercial Order ID.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testChargeUpdatedEmptyMetadataValue()
    {
        $id = StripeMock::CHARGE_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": ""},
                    "status": "pending"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals("$this->paymentKey is empty in Charge metadata.", $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testChargeUpdatedFailedStatus()
    {
        $id = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "failed",
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testChargeSucceededToCapture()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.succeeded",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals($chargeId, $paymentMapping->getStripeChargeId());
        $this->assertEquals($orderId, $paymentMapping->getMiraklCommercialOrderId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $this->assertEquals(100, $paymentMapping->getStripeAmount());
    }

    public function testChargeUpdatedToCapture()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals($chargeId, $paymentMapping->getStripeChargeId());
        $this->assertEquals($orderId, $paymentMapping->getMiraklCommercialOrderId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $this->assertEquals(100, $paymentMapping->getStripeAmount());
    }

    public function testChargeUpdatedCaptured()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertEquals(PaymentMapping::CAPTURED, $paymentMapping->getStatus());
    }

    public function testChargeCaptured()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.captured",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals(PaymentMapping::CAPTURED, $paymentMapping->getStatus());
    }

    public function testChargeUpdatedExistingMappingNewStatus()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $this->mockPaymentMapping($orderId, $chargeId, false, 'capture_failed: Previous capture failed');
        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $this->assertEquals('capture_failed: Previous capture failed', $paymentMapping->getStatusReason());
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping updated.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertEquals(PaymentMapping::CAPTURED, $paymentMapping->getStatus());
        $this->assertNull($paymentMapping->getStatusReason());
    }

    public function testChargeUpdatedExistingMappingSameStatus()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $this->mockPaymentMapping($orderId, $chargeId, false);
        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping updated.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
    }

    public function testChargeUpdatedMetadataInPaymentIntent()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $paymentIntentId = StripeMock::PAYMENT_INTENT_WITH_METADATA;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100,
                    "payment_intent": "$paymentIntentId"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals($chargeId, $paymentMapping->getStripeChargeId());
        $this->assertNotEmpty($paymentMapping->getMiraklCommercialOrderId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $this->assertEquals(100, $paymentMapping->getStripeAmount());
    }

    public function testChargeUpdatedMetadataInExpandedPaymentIntent()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $paymentIntentId = StripeMock::PAYMENT_INTENT_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100,
                    "payment_intent": {
                        "id": "$paymentIntentId",
                        "object": "payment_intent",
                        "metadata": {
                            "$this->paymentKey": "$orderId"
                        }
                    }
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals($chargeId, $paymentMapping->getStripeChargeId());
        $this->assertEquals($orderId, $paymentMapping->getMiraklCommercialOrderId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
        $this->assertEquals(100, $paymentMapping->getStripeAmount());
    }

    private function mockStripePayout(int $invoiceId, string $payoutId, string $status = StripePayout::PAYOUT_CREATED): StripePayout
    {
        $accountMapping = $this->accountMappingRepository->findOneByStripeAccountId(StripeMock::ACCOUNT_BASIC);

        $payout = new StripePayout();
        $payout->setMiraklInvoiceId($invoiceId);
        $payout->setPayoutId($payoutId);
        $payout->setAmount(1000);
        $payout->setCurrency('eur');
        $payout->setStatus($status);
        $payout->setAccountMapping($accountMapping);
        $payout->setMiraklCreatedDate(new \DateTime());

        $this->stripePayoutRepository->persistAndFlush($payout);

        return $payout;
    }

    public function testPayoutFailedUnknownPayout()
    {
        $payoutId = 'po_unknown';
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "payout.failed",
            "data": {
                "object": {
                    "id": "$payoutId",
                    "object": "payout",
                    "failure_message": "account_closed"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring payout.failed event for unknown payout.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testPayoutFailedKnownPayout()
    {
        $payoutId = StripeMock::PAYOUT_BASIC;
        $this->mockStripePayout(9999, $payoutId);

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "payout.failed",
            "data": {
                "object": {
                    "id": "$payoutId",
                    "object": "payout",
                    "failure_code": "account_closed",
                    "failure_message": "The bank account has been closed"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payout status updated to failed.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $updatedPayout = $this->stripePayoutRepository->findOneByPayoutId($payoutId);
        $this->assertNotNull($updatedPayout);
        $this->assertEquals(StripePayout::PAYOUT_FAILED, $updatedPayout->getStatus());
        $this->assertEquals('account_closed: The bank account has been closed', $updatedPayout->getStatusReason());
    }

    public function testPayoutFailedNoFailureMessage()
    {
        $payoutId = StripeMock::PAYOUT_BASIC;
        $this->mockStripePayout(9998, $payoutId);

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "payout.failed",
            "data": {
                "object": {
                    "id": "$payoutId",
                    "object": "payout"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payout status updated to failed.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $updatedPayout = $this->stripePayoutRepository->findOneByPayoutId($payoutId);
        $this->assertNotNull($updatedPayout);
        $this->assertEquals(StripePayout::PAYOUT_FAILED, $updatedPayout->getStatus());
        $this->assertEquals('unknown_code: Unknown failure reason', $updatedPayout->getStatusReason());
    }

    public function testChargeUpdatedWithStripeAccountMatchingOrder()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_ALL_VALIDATED;
        $stripeAccountId = StripeMock::ACCOUNT_BASIC;

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$stripeAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Payment mapping created.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($paymentMapping);
        $this->assertEquals($orderId, $paymentMapping->getMiraklCommercialOrderId());
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $paymentMapping->getStatus());
    }

    public function testChargeUpdatedWithStripeAccountMismatch()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_ALL_VALIDATED;
        $stripeAccountId = StripeMock::ACCOUNT_NEW;

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$stripeAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring event for unknown Stripe account.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNull($paymentMapping);
    }

    public function testChargeUpdatedWithStripeAccountNoMiraklOrder()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_NOT_FOUND;
        $stripeAccountId = StripeMock::ACCOUNT_BASIC;

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$stripeAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring event with no Mirakl Order.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNull($paymentMapping);
    }

    public function testChargeUpdatedWithStripeAccountUnknownMiraklShop()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_INVALID_SHOP;
        $stripeAccountId = StripeMock::ACCOUNT_BASIC;

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$stripeAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring event for unknown Mirakl shop.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $paymentMapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNull($paymentMapping);
    }

    public function testChargeUpdatedMetadataInExpandedPaymentIntentIsEmpty()
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $paymentIntentId = StripeMock::PAYMENT_INTENT_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100,
                    "payment_intent": {
                        "id": "$paymentIntentId",
                        "object": "payment_intent",
                        "metadata": {
                            "$this->paymentKey": ""
                        }
                    }
                }
            }
        }
        PAYLOAD);
        $this->assertEquals("$this->paymentKey is empty in PaymentIntent.", $response->getContent());
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Security fix: duplicate commercial-order guard
    // -------------------------------------------------------------------------

    /**
     * A second charge that claims the same commercial order ID must be rejected
     * once a PaymentMapping already exists for that order. The existing mapping
     * (for a different charge) must remain intact.
     */
    public function testChargeUpdatedSecondChargeForSameCommercialOrderIsRejected(): void
    {
        $existingChargeId = 'ch_already_mapped';
        $newChargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;

        // Pre-populate the mapping created by the first (legitimate) webhook.
        $this->mockPaymentMapping($orderId, $existingChargeId, false);

        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$newChargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 200
                }
            }
        }
        PAYLOAD);

        $this->assertEquals(
            'Ignoring event: payment mapping already exists for this commercial order.',
            $response->getContent()
        );
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // The original mapping must be untouched.
        $originalMapping = $this->paymentMappingRepository->findOneByStripeChargeId($existingChargeId);
        $this->assertNotNull($originalMapping);
        $this->assertEquals($orderId, $originalMapping->getMiraklCommercialOrderId());

        // No new mapping must have been created for the second charge.
        $newMapping = $this->paymentMappingRepository->findOneByStripeChargeId($newChargeId);
        $this->assertNull($newMapping);
    }

    /**
     * When the same charge is seen again (idempotent re-delivery), the existing
     * mapping must be updated normally — the duplicate guard must not fire because
     * the charge ID is already in the mapping, so the code takes the update branch.
     */
    public function testChargeUpdatedIdempotentRedeliveryUpdatesExistingMapping(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;

        // Existing mapping: same charge, same order, not yet captured.
        $this->mockPaymentMapping($orderId, $chargeId, false);

        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        // Should follow the update branch, not the duplicate guard.
        $this->assertEquals('Payment mapping updated.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $mapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($mapping);
        $this->assertEquals(PaymentMapping::CAPTURED, $mapping->getStatus());
        $this->assertEquals($orderId, $mapping->getMiraklCommercialOrderId());
    }

    // -------------------------------------------------------------------------
    // Security fix: charge reassignment guard
    // -------------------------------------------------------------------------

    /**
     * An attacker controlling a known charge ID must not be able to reassign that
     * charge to a different commercial order via a charge.updated event. The event
     * must be silently ignored and the original mapping left unchanged.
     */
    public function testChargeUpdatedReassignmentToDifferentCommercialOrderIsRejected(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $originalOrderId = MiraklMock::ORDER_BASIC;
        $attackerOrderId = MiraklMock::ORDER_COMMERCIAL_ALL_VALIDATED;

        // Legitimate existing mapping: charge → originalOrder.
        $this->mockPaymentMapping($originalOrderId, $chargeId, false);

        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$attackerOrderId"},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals(
            'Ignoring event: charge is already mapped to a different commercial order.',
            $response->getContent()
        );
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // The mapping must not have been moved to the attacker-controlled order.
        $mapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($mapping);
        $this->assertEquals($originalOrderId, $mapping->getMiraklCommercialOrderId());
        // Status must also be unchanged (TO_CAPTURE, not CAPTURED).
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $mapping->getStatus());
    }

    /**
     * Updating the same charge to a null commercial order ID (no metadata) should
     * leave the mapping in place and not trigger the reassignment guard, since the
     * event is rejected earlier by the "no commercial order ID" check.
     */
    public function testChargeUpdatedNoMetadataDoesNotAffectExistingMapping(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_BASIC;

        $this->mockPaymentMapping($orderId, $chargeId, false);

        // Event with no metadata at all.
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {},
                    "status": "succeeded",
                    "captured": true,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals('Ignoring event with no Mirakl Commercial Order ID.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // Existing mapping must be untouched.
        $mapping = $this->paymentMappingRepository->findOneByStripeChargeId($chargeId);
        $this->assertNotNull($mapping);
        $this->assertEquals(PaymentMapping::TO_CAPTURE, $mapping->getStatus());
    }

    // -------------------------------------------------------------------------
    // Security fix: multi-seller authorization
    //
    // AccountMapping enforces stripe_account_id UNIQUE, so two Mirakl shops can
    // never share a Stripe connected account. A connected-account event for a
    // multi-shop commercial order therefore ALWAYS maps shops to different accounts
    // and is always rejected. The positive case (single seller, matching account)
    // is already covered by testChargeUpdatedWithStripeAccountMatchingOrder.
    // -------------------------------------------------------------------------

    /**
     * A commercial order whose sub-orders come from two shops mapped to DIFFERENT
     * Stripe accounts must be rejected: a single connected-account event cannot
     * legitimately claim an order that spans multiple seller accounts.
     */
    public function testChargeUpdatedMultiSellerShopsMappedToDifferentAccountsIsRejected(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_TWO_SHOPS;
        $eventAccountId = StripeMock::ACCOUNT_NEW;

        // ORDER_COMMERCIAL_TWO_SHOPS returns SHOP_NOT_READY (99) and SHOP_NEW (299).
        // Map them to different accounts so the event must be rejected.
        $this->mockAccountMapping(MiraklMock::SHOP_NOT_READY, StripeMock::ACCOUNT_NEW, true);
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NOT_FOUND, true);

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$eventAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals('Ignoring event for unknown Stripe account.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // No mapping should have been created.
        $this->assertNull($this->paymentMappingRepository->findOneByStripeChargeId($chargeId));
    }

    /**
     * Even when the event account matches one of the shops, having any shop map to
     * a different account is still a rejection — the "all shops same account" rule
     * is not a "at least one shop matches" rule.
     */
    public function testChargeUpdatedMultiSellerPartialAccountMatchIsRejected(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_TWO_SHOPS;

        // SHOP_NOT_READY (99) → ACCOUNT_NOT_FOUND, SHOP_NEW (299) → ACCOUNT_NEW.
        // Sender is ACCOUNT_NEW — matches SHOP_NEW but not SHOP_NOT_READY → rejected.
        $this->mockAccountMapping(MiraklMock::SHOP_NOT_READY, StripeMock::ACCOUNT_NOT_FOUND, true);
        $this->mockAccountMapping(MiraklMock::SHOP_NEW, StripeMock::ACCOUNT_NEW, true);

        $senderAccount = StripeMock::ACCOUNT_NEW;
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$senderAccount",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals('Ignoring event for unknown Stripe account.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull($this->paymentMappingRepository->findOneByStripeChargeId($chargeId));
    }

    /**
     * If not every shop in the commercial order has an account mapping, the event
     * must be rejected — the count guard catches this.
     */
    public function testChargeUpdatedMultiSellerOnlyOneShopMappedIsRejected(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_TWO_SHOPS;

        // Map only SHOP_NOT_READY (99); leave SHOP_NEW (299) unmapped.
        // count(accountMappings)=1 != count(shopIds)=2 → "unknown Mirakl shop".
        $this->mockAccountMapping(MiraklMock::SHOP_NOT_READY, StripeMock::ACCOUNT_NEW, true);

        $senderAccount = StripeMock::ACCOUNT_NEW;
        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$senderAccount",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        $this->assertEquals('Ignoring event for unknown Mirakl shop.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull($this->paymentMappingRepository->findOneByStripeChargeId($chargeId));
    }

    // -------------------------------------------------------------------------
    // Security fix: hybrid orders — both product and service orders always fetched
    // -------------------------------------------------------------------------

    /**
     * A hybrid commercial order (product sub-order from SHOP_NOT_READY=99, service
     * sub-order from SHOP_NEW=299) must include the service shop in the authorization
     * check. When SHOP_NEW has no account mapping the event must be rejected.
     *
     * Before the fix, the service order list was skipped whenever product orders
     * existed, making it possible for a service seller's shop to bypass auth.
     */
    public function testChargeUpdatedHybridOrderServiceShopIsIncludedInAuthorization(): void
    {
        $chargeId = StripeMock::CHARGE_BASIC;
        $orderId = MiraklMock::ORDER_COMMERCIAL_HYBRID_SERVICE_DIFF_SHOP;
        $stripeAccountId = StripeMock::ACCOUNT_NEW;

        // Map the product sub-order's shop (SHOP_NOT_READY=99) but leave the service
        // sub-order's shop (SHOP_NEW=299) unmapped. count(mappings)=1 ≠ count(shops)=2
        // → rejected, proving the service shop IS included in the authorization check.
        $this->mockAccountMapping(MiraklMock::SHOP_NOT_READY, $stripeAccountId, true);

        $response = $this->executeSellersRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "account": "$stripeAccountId",
            "data": {
                "object": {
                    "id": "$chargeId",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": "$orderId"},
                    "status": "succeeded",
                    "captured": false,
                    "amount": 100
                }
            }
        }
        PAYLOAD);

        // Service shop not in account mappings → count mismatch → rejected.
        $this->assertEquals('Ignoring event for unknown Mirakl shop.', $response->getContent());
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNull($this->paymentMappingRepository->findOneByStripeChargeId($chargeId));
    }

}
