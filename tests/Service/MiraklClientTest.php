<?php

namespace App\Tests\Factory;

use App\Service\MiraklClient;
use App\Tests\MiraklMockedHttpClient;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\Response\MockResponse;

class MiraklClientTest extends KernelTestCase {
    /**
     * @var MiraklClient
     */
    private $miraklClient;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $container = self::$kernel->getContainer();
        $application = new Application($kernel);

        $this->miraklClient = $container->get('App\Service\MiraklClient');
    }

    public function testGetNextLink()
    {
        $prevLink = 'https://test.mirakl.net/api/orders?commercial_ids=3693596968,0195242688,3884560115,2845696461,5526974611,3962904573,0055278822,0253203047,8674454819,2173887289,9577383944,1730096837,7195291116,7569009629,4878630488,5259284619,7978839735,3766272697,9557235094,2201264206,1131931008,7338035900&max=2&offset=0>; rel="previous"';
        $nextLink = 'https://test-dev.mirakl.net/api/orders?commercial_ids=3693596968,0195242688,3884560115,2845696461,5526974611,3962904573,0055278822,0253203047,8674454819,2173887289,9577383944,1730096837,7195291116,7569009629,4878630488,5259284619,7978839735,3766272697,9557235094,2201264206,1131931008,7338035900&max=2&offset=2';

        $nextLinkHeader = "<{$nextLink}>; rel=\"next\"";
        $responseNext = new MockResponse('', [
            'http_code' => 200,
            'response_headers' => ['Link' => $nextLinkHeader]
        ]);
        $link  = $this->miraklClient->getNextLink($responseNext);
        $this->assertEquals($nextLink, $link);

        $prevNextLinkHeader = "<{$prevLink}>; rel=\"previous\", <{$nextLink}>; rel=\"next\"";
        $responsePrevNext = new MockResponse('', [
            'http_code' => 200,
            'response_headers' => ['Link' => $prevNextLinkHeader]
        ]);
        $link  = $this->miraklClient->getNextLink($responsePrevNext);
        $this->assertEquals($nextLink, $link);
    }

    public function testGetMoreThanOneHundredOrdersById()
    {
        $orderIds = [];
        $orderIds[] = MiraklMockedHttpClient::ORDER_STATUS_SHIPPING;
        for ($i = 0; $i < 100; $i += 1) {
            $orderIds[] = "random_order_$i";
        }
        $orderIds[] = MiraklMockedHttpClient::ORDER_STATUS_SHIPPED;

        $res = $this->miraklClient->listProductOrdersById($orderIds);

        $this->assertCount(102, $res);
        $this->assertArrayHasKey(MiraklMockedHttpClient::ORDER_STATUS_SHIPPING, $res);
        $this->assertArrayHasKey(MiraklMockedHttpClient::ORDER_STATUS_SHIPPED, $res);
    }

    public function testGetMoreThanOneHundredOrdersByCommercialId()
    {
        $commercialIds = array_fill(0, 99, MiraklMockedHttpClient::ORDER_COMMERCIAL_NONE_VALIDATED);
        $commercialIds[] = MiraklMockedHttpClient::ORDER_COMMERCIAL_PARTIALLY_VALIDATED;
        $commercialIds[] = MiraklMockedHttpClient::ORDER_COMMERCIAL_ALL_VALIDATED;

        $res = $this->miraklClient->listProductOrdersByCommercialId($commercialIds);

        $this->assertArrayHasKey(MiraklMockedHttpClient::ORDER_COMMERCIAL_NONE_VALIDATED, $res);
        $this->assertArrayHasKey(MiraklMockedHttpClient::ORDER_COMMERCIAL_PARTIALLY_VALIDATED, $res);
        $this->assertArrayHasKey(MiraklMockedHttpClient::ORDER_COMMERCIAL_ALL_VALIDATED, $res);
    }
}

?>
