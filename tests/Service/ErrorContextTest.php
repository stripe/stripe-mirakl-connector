<?php

namespace App\Tests\Service;

use App\Service\ErrorContext;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ErrorContextTest extends TestCase
{
    public function testStripeExceptionContextDoesNotContainMessage(): void
    {
        $e = InvalidRequestException::factory(
            'Account link https://connect.stripe.com/setup/s/abc is invalid',
            400,
            null,
            null,
            null,
            'resource_missing'
        );

        $context = ErrorContext::fromThrowable($e);

        $this->assertSame(InvalidRequestException::class, $context['exception']);
        $this->assertSame('resource_missing', $context['stripeErrorCode']);
        $this->assertSame(400, $context['httpStatus']);
        $this->assertStringNotContainsString('connect.stripe.com', json_encode($context));
    }

    public function testHttpClientExceptionContextDoesNotContainBody(): void
    {
        $client = new MockHttpClient(new MockResponse(
            '{"message":"invalid value https://connect.stripe.com/setup/s/abc"}',
            ['http_code' => 400]
        ));
        try {
            $client->request('PUT', 'https://mirakl.example/api/shops')->getContent();
            $this->fail('Expected ClientException');
        } catch (ClientException $e) {
            $context = ErrorContext::fromThrowable($e);
        }

        $this->assertSame(ClientException::class, $context['exception']);
        $this->assertSame(400, $context['httpStatus']);
        $this->assertStringNotContainsString('connect.stripe.com', json_encode($context));
    }

    public function testGenericExceptionOnlyHasClass(): void
    {
        $this->assertSame(['exception' => \RuntimeException::class], ErrorContext::fromThrowable(new \RuntimeException('secret')));
    }
}
