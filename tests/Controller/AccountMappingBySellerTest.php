<?php

namespace App\Tests\Controller;

use App\Controller\AccountMappingBySeller;
use App\Entity\AccountMapping;
use App\Entity\AccountOnboarding;
use App\Repository\AccountMappingRepository;
use App\Repository\AccountOnboardingRepository;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class AccountMappingBySellerTest extends TestCase
{
    protected $controller;
    protected $accountMappingRepository;
    protected $accountOnboardingRepository;
    protected $stripeClient;
    protected $redirectOnboarding;
    protected $stripeApiErrorException;
    protected $exceptionMessage;

    protected function setUp(): void
    {
        $this->stripeClient = $this->createMock(StripeClient::class);

        $this->redirectOnboarding = 'https://shopredirect.com';
        $this->exceptionMessage = 'A Stripe error occurred';
        $this->accountMappingRepository = $this->getMockBuilder(AccountMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByMiraklShopId', 'persistAndFlush'])
            ->getMock();
        $this->accountOnboardingRepository = $this->getMockBuilder(AccountOnboardingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeState', 'deleteAndFlush'])
            ->getMock();
        $this->stripeApiErrorException = $this->getMockBuilder(ApiErrorException::class)
            ->setConstructorArgs([$this->exceptionMessage])
            ->setMethods(['getStripeCode'])
            ->getMock();
        $logger = new NullLogger();

        $this->controller = new AccountMappingBySeller(
            $this->stripeClient,
            $this->redirectOnboarding,
            $this->accountMappingRepository,
            $this->accountOnboardingRepository
        );
        $this->controller->setLogger($logger);
    }

    public function testLinkShopWithMissingCode()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['state' => 'hash']
        );
        $response = $this->controller->linkShop($request);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());

        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => AccountMappingBySeller::ERROR_MISSING_CODE['code'],
            'error_description' => AccountMappingBySeller::ERROR_MISSING_CODE['description'],
        ]);
        $redirectUrl = sprintf('%s?%s', $this->redirectOnboarding, $queryParams);

        $this->assertEquals($redirectUrl, $response->headers->get('Location'));
    }

    public function testLinkShopWithMissingState()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['code' => 'validCode']
        );
        $response = $this->controller->linkShop($request);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());

        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => AccountMappingBySeller::ERROR_MISSING_STATE['code'],
            'error_description' => AccountMappingBySeller::ERROR_MISSING_STATE['description'],
        ]);
        $redirectUrl = sprintf('%s?%s', $this->redirectOnboarding, $queryParams);

        $this->assertEquals($redirectUrl, $response->headers->get('Location'));
    }

    public function testLinkShopWithUnknownState()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['state' => 'unknown', 'code' => 'unused']
        );
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('unknown')
            ->willReturn(null);

        $response = $this->controller->linkShop($request);
        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => AccountMappingBySeller::ERROR_NO_MATCHING_STATE['code'],
            'error_description' => AccountMappingBySeller::ERROR_NO_MATCHING_STATE['description'],
        ]);
        $redirectUrl = sprintf('%s?%s', $this->redirectOnboarding, $queryParams);

        $this->assertEquals($redirectUrl, $response->headers->get('Location'));
    }

    public function testLinkShopWithAlreadyLinkedShop()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['state' => 'hash', 'code' => 'unused']
        );
        $accountOnboarding = (new AccountOnboarding())->setMiraklShopId(4242);
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($accountOnboarding);
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($accountOnboarding);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(new AccountMapping());

        $response = $this->controller->linkShop($request);
        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => AccountMappingBySeller::ERROR_ALREADY_EXISTING_SHOP['code'],
            'error_description' => AccountMappingBySeller::ERROR_ALREADY_EXISTING_SHOP['description'],
        ]);
        $redirectUrl = sprintf('%s?%s', $this->redirectOnboarding, $queryParams);

        $this->assertEquals($redirectUrl, $response->headers->get('Location'));
    }

    public function testLinkShopWithStripeApiErrorException()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['state' => 'hash', 'code' => 'validCode']
        );
        $accountOnboarding = (new AccountOnboarding())->setMiraklShopId(4242);
        $exceptionCode = 'stripe_error';
        $this
            ->stripeApiErrorException
            ->expects($this->once())
            ->method('getStripeCode')
            ->willReturn($exceptionCode);
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($accountOnboarding);
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($accountOnboarding);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(null);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('loginWithCode')
            ->with('validCode')
            ->will($this->throwException($this->stripeApiErrorException));

        $response = $this->controller->linkShop($request);
        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => $exceptionCode,
            'error_description' => $this->exceptionMessage,
        ]);
        $redirectUrl = sprintf('%s?%s', $this->redirectOnboarding, $queryParams);

        $this->assertEquals($redirectUrl, $response->headers->get('Location'));
    }

    public function testLinkShop()
    {
        $request = Request::create(
            '/api/onboarding/create_mapping',
            'GET',
            ['state' => 'hash', 'code' => 'validCode']
        );
        $accountOnboarding = (new AccountOnboarding())->setMiraklShopId(4242);
        $stripeLoginResponse = new StripeObject();
        $stripeLoginResponse->stripe_user_id = 'acct_valid';

        $stripeAccount = new Account('acct_valid');
        $stripeAccount->payouts_enabled = false;
        $stripeAccount->charges_enabled = true;
        $stripeAccount->requirements = [
            'disabled_reason' => 'check in progress',
        ];
        $expectedMapping = new AccountMapping();
        $expectedMapping
            ->setMiraklShopId(4242)
            ->setStripeAccountId('acct_valid')
            ->setPayoutEnabled(false)
            ->setPayinEnabled(true)
            ->setDisabledReason('check in progress');

        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($accountOnboarding);
        $this
            ->accountOnboardingRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($accountOnboarding);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(null);
        $this
            ->accountMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('loginWithCode')
            ->with('validCode')
            ->willReturn($stripeLoginResponse);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('setPayoutToManual')
            ->with('acct_valid')
            ->willReturn($stripeAccount);
        $this
            ->stripeClient
            ->expects($this->once())
            ->method('setMiraklShopId')
            ->with('acct_valid', 4242)
            ->willReturn($stripeAccount);

        $response = $this->controller->linkShop($request);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('https://shopredirect.com?success=true&mirakl_shop_id=4242', $response->headers->get('Location'));
    }
}
