<?php

namespace App\Tests\Controller;

use App\Controller\CreateMappingFromStripe;
use App\Entity\MiraklStripeMapping;
use App\Entity\OnboardingAccount;
use App\Repository\MiraklStripeMappingRepository;
use App\Repository\OnboardingAccountRepository;
use App\Repository\StripeAccountRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Account;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class CreateMappingFromStripeTest extends TestCase
{
    protected $controller;
    protected $miraklStripeMappingRepository;
    protected $onboardingAccountRepository;
    protected $stripeAccountRepository;
    protected $redirectOnboarding;
    protected $stripeApiErrorException;
    protected $exceptionMessage;

    protected function setUp(): void
    {
        $this->stripeAccountRepository = $this->createMock(StripeAccountRepository::class);

        $this->redirectOnboarding = 'https://shopredirect.com';
        $this->exceptionMessage = 'A Stripe error occurred';
        $this->miraklStripeMappingRepository = $this->getMockBuilder(MiraklStripeMappingRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByMiraklShopId', 'persistAndFlush'])
            ->getMock();
        $this->onboardingAccountRepository = $this->getMockBuilder(OnboardingAccountRepository::class)
            ->disableOriginalConstructor()
            ->setMethods(['findOneByStripeState', 'deleteAndFlush'])
            ->getMock();
        $this->stripeApiErrorException = $this->getMockBuilder(ApiErrorException::class)
            ->setConstructorArgs([$this->exceptionMessage])
            ->setMethods(['getStripeCode'])
            ->getMock();
        $logger = new NullLogger();

        $this->controller = new CreateMappingFromStripe(
            $this->stripeAccountRepository,
            $this->redirectOnboarding,
            $this->miraklStripeMappingRepository,
            $this->onboardingAccountRepository
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
            'error_code' => CreateMappingFromStripe::ERROR_MISSING_CODE['code'],
            'error_description' => CreateMappingFromStripe::ERROR_MISSING_CODE['description'],
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
            'error_code' => CreateMappingFromStripe::ERROR_MISSING_STATE['code'],
            'error_description' => CreateMappingFromStripe::ERROR_MISSING_STATE['description'],
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
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('unknown')
            ->willReturn(null);

        $response = $this->controller->linkShop($request);
        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => CreateMappingFromStripe::ERROR_NO_MATCHING_STATE['code'],
            'error_description' => CreateMappingFromStripe::ERROR_NO_MATCHING_STATE['description'],
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
        $onboardingAccount = (new OnboardingAccount())->setMiraklShopId(4242);
        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($onboardingAccount);
        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($onboardingAccount);
        $this
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(new MiraklStripeMapping());

        $response = $this->controller->linkShop($request);
        $queryParams = \http_build_query([
            'error' => 'true',
            'error_code' => CreateMappingFromStripe::ERROR_ALREADY_EXISTING_SHOP['code'],
            'error_description' => CreateMappingFromStripe::ERROR_ALREADY_EXISTING_SHOP['description'],
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
        $onboardingAccount = (new OnboardingAccount())->setMiraklShopId(4242);
        $exceptionCode = 'stripe_error';
        $this
            ->stripeApiErrorException
            ->expects($this->once())
            ->method('getStripeCode')
            ->willReturn($exceptionCode);
        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($onboardingAccount);
        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($onboardingAccount);
        $this
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(null);
        $this
            ->stripeAccountRepository
            ->expects($this->once())
            ->method('findByCode')
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
        $onboardingAccount = (new OnboardingAccount())->setMiraklShopId(4242);
        $stripeLoginResponse = new StripeObject();
        $stripeLoginResponse->stripe_user_id = 'acct_valid';

        $stripeAccount = new Account('acct_valid');
        $stripeAccount->payouts_enabled = false;
        $stripeAccount->charges_enabled = true;
        $stripeAccount->requirements = [
            'disabled_reason' => 'check in progress',
        ];
        $expectedMapping = new MiraklStripeMapping();
        $expectedMapping
            ->setMiraklShopId(4242)
            ->setStripeAccountId('acct_valid')
            ->setPayoutEnabled(false)
            ->setPayinEnabled(true)
            ->setDisabledReason('check in progress');

        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('findOneByStripeState')
            ->with('hash')
            ->willReturn($onboardingAccount);
        $this
            ->onboardingAccountRepository
            ->expects($this->once())
            ->method('deleteAndFlush')
            ->with($onboardingAccount);
        $this
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('findOneByMiraklShopId')
            ->with(4242)
            ->willReturn(null);
        $this
            ->miraklStripeMappingRepository
            ->expects($this->once())
            ->method('persistAndFlush')
            ->with($expectedMapping);
        $this
            ->stripeAccountRepository
            ->expects($this->once())
            ->method('findByCode')
            ->with('validCode')
            ->willReturn($stripeLoginResponse);
        $this
            ->stripeAccountRepository
            ->expects($this->once())
            ->method('setManualPayout')
            ->with('acct_valid')
            ->willReturn($stripeAccount);

        $response = $this->controller->linkShop($request);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals('https://shopredirect.com?success=true&mirakl_shop_id=4242', $response->headers->get('Location'));
    }
}
