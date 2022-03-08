<?php

namespace App\Tests\Controller;

use App\Entity\AccountMapping;
use App\Entity\PaymentMapping;
use App\Repository\AccountMappingRepository;
use App\Repository\PaymentMappingRepository;
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
     * @var InMemoryTransport
     */
    protected $updateLoginLinkQueue;

    protected function setUp(): void
    {
        $this->client =  self::createClient();
        $this->accountMappingRepository = self::$container->get('doctrine')->getRepository(AccountMapping::class);
        $this->paymentMappingRepository = self::$container->get('doctrine')->getRepository(PaymentMapping::class);
        $this->updateLoginLinkQueue = self::$container->get('messenger.transport.update_login_link');
        $this->paymentKey = self::$container->getParameter('app.workflow.payment_metadata_commercial_order_id');
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

    private function mockPaymentMapping(string $orderId, string $chargeId, bool $captured = false)
    {
        $paymentMapping = new PaymentMapping();
        $paymentMapping->setMiraklCommercialOrderId($orderId);
        $paymentMapping->setStripeChargeId($chargeId);
        $paymentMapping->setStatus($captured ? PaymentMapping::CAPTURED : PaymentMapping::TO_CAPTURE);

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
        $this->assertEquals('The event type payment_intent.created is no longer required and can be removed in the webhook settings.', $response->getContent());
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
        $response = $this->executeOperatorRequest(<<<PAYLOAD
        {
            "type": "charge.updated",
            "data": {
                "object": {
                    "id": "$id",
                    "object": "charge",
                    "metadata": {"$this->paymentKey": ""},
                    "status": "failed"
                }
            }
        }
        PAYLOAD);
        $this->assertEquals('Ignoring failed charge event.', $response->getContent());
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

    public function testChargeUpdatedExistingMappingNewStatus()
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
}
