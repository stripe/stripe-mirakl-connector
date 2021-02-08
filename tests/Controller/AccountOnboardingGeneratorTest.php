<?php

namespace App\Tests\Controller;

use App\Controller\AccountOnboardingGenerator;
use App\DTO\AccountOnboardingDTO;
use App\Exception\InvalidArgumentException;
use App\Factory\AccountOnboardingFactory;
use App\Tests\MiraklMockedHttpClient as MiraklMock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class AccountOnboardingGeneratorTest extends TestCase
{
    const STRIPE_CLIENT_ID = 'stripe-client';

    protected $controller;
    protected $accountOnboardingFactory;
    protected $serializer;
    protected $validator;

    protected function setUp(): void
    {
        $this->accountOnboardingFactory = $this->createMock(AccountOnboardingFactory::class);
        $this->serializer = $this->createMock(SerializerInterface::class);
        $this->validator = $this->createMock(ValidatorInterface::class);

        $logger = new NullLogger();

        $this->controller = new AccountOnboardingGenerator(
            $this->accountOnboardingFactory,
            $this->serializer,
            $this->validator
        );
        $this->controller->setLogger($logger);
    }

    public function testGenerateStripeOnboardingLinkWithInvalidMiraklShopId()
    {
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            ['miraklShopId' => MiraklMock::SHOP_BASIC]
        );
        $expectedAccountOnboardingDto = new AccountOnboardingDTO();
        $expectedAccountOnboardingDto->setMiraklShopId(MiraklMock::SHOP_BASIC);

        $this
            ->serializer
            ->expects($this->once())
            ->method('deserialize')
            ->willReturn($expectedAccountOnboardingDto);

        $this
            ->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn(['error']);

        $response = $this->controller->generateStripeOnboardingLink($request);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());
    }

    public function testGenerateStripeOnboardingLinkWithExistingShop()
    {
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            ['miraklShopId' => MiraklMock::SHOP_BASIC]
        );
        $expectedAccountOnboardingDto = new AccountOnboardingDTO();
        $expectedAccountOnboardingDto->setMiraklShopId(MiraklMock::SHOP_BASIC);

        $this
            ->serializer
            ->expects($this->once())
            ->method('deserialize')
            ->willReturn($expectedAccountOnboardingDto);
        $this
            ->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn([]);
        $this
            ->accountOnboardingFactory
            ->expects($this->once())
            ->method('createFromMiraklShopId')
            ->with(MiraklMock::SHOP_BASIC)
            ->willThrowException(new InvalidArgumentException('Mapping already exists'));

        $response = $this->controller->generateStripeOnboardingLink($request);
        $this->assertEquals(Response::HTTP_CONFLICT, $response->getStatusCode());
    }

    public function testGenerateStripeOnboardingLink()
    {
        $request = Request::create(
            '/api/public/webhook',
            'POST',
            ['miraklShopId' => MiraklMock::SHOP_BASIC]
        );
        $expectedAccountOnboardingDto = new AccountOnboardingDTO();
        $expectedAccountOnboardingDto->setMiraklShopId(MiraklMock::SHOP_BASIC);

        $miraklSeller = [
            'contact_informations' => [
                'email' => 'prefilledEmail@example.com',
                'web_site' => 'https://website.com',
                'country' => 'FRANCE',
                'phone' => '0987654321',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
            ],
            'is_professional' => true,
            'shop_name' => 'Prefilled Shop',
        ];
        $this
            ->serializer
            ->expects($this->once())
            ->method('deserialize')
            ->willReturn($expectedAccountOnboardingDto);

        $this
            ->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn([]);
        $this
            ->accountOnboardingFactory
            ->expects($this->once())
            ->method('createFromMiraklShopId')
            ->with(MiraklMock::SHOP_BASIC)
            ->willReturn('stripeRedirectUrl');

        $response = $this->controller->generateStripeOnboardingLink($request);
        $expectedResponse = new JsonResponse(['redirect_url' => 'stripeRedirectUrl']);

        $this->assertEquals($expectedResponse, $response);
    }
}
