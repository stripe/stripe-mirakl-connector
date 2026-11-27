<?php

namespace App\Service;

use Stripe\HttpClient\ClientInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final class StripeHttpClient implements ClientInterface
{
    public function __construct(private ClientInterface $client)
    {
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $response = $this->client->request($method, $absUrl, $headers, $params, $hasFile);

        // The Stripe SDK emits this advisory as a PHP warning before processing the response.
        unset($response[2]['stripe-notice']);

        return $response;
    }
}
