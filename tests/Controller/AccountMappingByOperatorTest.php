<?php

namespace App\Tests\Controller;

use App\Controller\AccountMappingByOperator;
use App\DTO\AccountMappingDTO;
use App\Entity\AccountMapping;
use App\Repository\AccountMappingRepository;
use App\Service\StripeClient;
use App\Tests\ConnectorWebTestCase;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use App\Tests\StripeMockedHttpClient as StripeMock;
use Psr\Log\NullLogger;
use Stripe\Account;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AccountMappingByOperatorTest extends ConnectorWebTestCase
{
    protected $controller;
    protected $accountMappingRepository;
    protected $stripeClient;
    protected $serializer;
    protected $validator;
    protected $uniqueConstraintViolationException;

    protected function setUp(): void
    {
        $this->stripeClient = $this->getMockBuilder(StripeClient::class)
            ->disableOriginalConstructor()
            ->setMethods(['setPayoutToManual'])
            ->getMock();

        $this->accountMappingRepository = $this->getMockBuilder(AccountMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByMiraklShopId', 'persistAndFlush'])
            ->getMock();

        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        $logger = new NullLogger();

        $this->controller = new AccountMappingByOperator(
            $this->accountMappingRepository,
            $this->stripeClient,
            $this->serializer,
            $this->validator
        );
        $this->controller->setLogger($logger);
    }

    public function testBadMiraklIdFormatMapping()
    {
        $miraklShopId = 4242;
        $stripeUserId = 'acct_valid';
        $request = Request::create(
            '/api/mappings',
            'POST',
            ['miraklShopId' => $miraklShopId, 'stripeUserId' => $stripeUserId]
        );
        $stripeLoginResponse = new StripeObject();
        $stripeLoginResponse->stripe_user_id = $stripeUserId;

        $stripeAccount = new Account('acct_valid');
        $stripeAccount->payouts_enabled = false;
        $stripeAccount->charges_enabled = true;
        $stripeAccount->requirements = [
            'disabled_reason' => 'check in progress',
        ];

        $expectedMappingDto = new AccountMappingDTO();
        $expectedMappingDto
            ->setMiraklShopId($miraklShopId)
            ->setStripeUserId($stripeUserId);

        $this
            ->serializer
            ->expects($this->once())
            ->method('deserialize')
            ->willReturn($expectedMappingDto);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('setPayoutToManual')
            ->with($stripeUserId)
            ->willReturn($stripeAccount);
        $this
            ->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn(['error']);

        $response = $this->controller->createMapping($request);

        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGenerateMapping()
    {
        $miraklShopId = 4242;
        $stripeUserId = 'acct_valid';
        $request = Request::create(
            '/api/mappings',
            'POST',
            ['miraklShopId' => $miraklShopId, 'stripeUserId' => $stripeUserId]
        );
        $stripeLoginResponse = new StripeObject();
        $stripeLoginResponse->stripe_user_id = $stripeUserId;

        $stripeAccount = new Account('acct_valid');
        $stripeAccount->payouts_enabled = false;
        $stripeAccount->charges_enabled = true;
        $stripeAccount->requirements = [
            'disabled_reason' => 'check in progress',
        ];

        $expectedMappingDto = new AccountMappingDTO();
        $expectedMappingDto
            ->setMiraklShopId($miraklShopId)
            ->setStripeUserId($stripeUserId);
        $expectedMapping = new AccountMapping();
        $expectedMapping
            ->setMiraklShopId($miraklShopId)
            ->setStripeAccountId($stripeUserId)
            ->setPayoutEnabled(false)
            ->setPayinEnabled(true)
            ->setDisabledReason('check in progress');

        $this
            ->serializer
            ->expects($this->once())
            ->method('deserialize')
            ->willReturn($expectedMappingDto);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('setPayoutToManual')
            ->with($stripeUserId)
            ->willReturn($stripeAccount);
        $this
            ->accountMappingFactory
            ->expects($this->once())
            ->method('createMappingFromDTO')
            ->with($expectedMappingDto)
            ->willReturn($expectedMapping);
        $this
            ->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn([]);

        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);

        $response = $this->controller->createMapping($request);

        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
    }
}
