<?php

namespace App\Tests\Controller;

use App\Entity\AccountOnboarding;
use App\Tests\ConnectorWebTestCase;
use Hautelook\AliceBundle\PhpUnit\RecreateDatabaseTrait;
use Symfony\Component\HttpFoundation\Response;

class AccountMappingBySellerWebTest extends ConnectorWebTestCase
{
    use RecreateDatabaseTrait;

    protected $mockedStripeClient;

    public function testLinkShop()
    {
        $client = static::createClient();
        $repository = self::$container->get('doctrine')->getRepository(AccountOnboarding::class);
        $this->assertNotNull($repository->findOneByStripeState('state_11'));

        $client->request(
            'GET',
            '/api/public/onboarding/create_mapping?state=state_11&code=validCode'
        );

        $response = $client->getResponse();
        $redirectUrl = $response->headers->get('location');
        $parsedQueryParams = [];
        parse_str(parse_url($redirectUrl)['query'], $parsedQueryParams);
        $this->assertEquals(Response::HTTP_FOUND, $response->getStatusCode());
        $this->assertEquals($parsedQueryParams['success'], 'true');
        $this->assertEquals($parsedQueryParams['mirakl_shop_id'], '11');

        $this->assertNull($repository->findOneByStripeState('state_11'));
    }
}
