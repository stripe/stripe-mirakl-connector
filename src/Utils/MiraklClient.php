<?php

namespace App\Utils;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Separated (and public) methods for test purposes, as it is not possible to mock static calls.
 *
 * @codeCoverageIgnore
 */
class MiraklClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    const DATE_FORMAT = \DateTime::ISO8601;

    /**
     * @var HttpClientInterface
     */
    private $client;

    public function __construct(HttpClientInterface $miraklClient)
    {
        $this->client = $miraklClient;
    }

    // GET OR11
    public function listMiraklOrders(?\DateTimeInterface $lastMiraklUpdateTime, ?array $orderIds)
    {
        $filters = ['query' => []];
        $filters['query']['customer_debited'] = 'true';

        if (null !== $lastMiraklUpdateTime) {
            $filters['query']['start_update_date'] = $lastMiraklUpdateTime->format(self::DATE_FORMAT);
        }

        if (null !== $orderIds) {
            $filters['query']['order_ids'] = implode(',', $orderIds);
        }

        $this->logger->info('[Mirakl API] Call to OR11 - fetch orders');
        $response = $this->client->request('GET', '/api/orders', $filters);

        return json_decode($response->getContent(), true)['orders'];
    }

    // GET IV01
    public function listMiraklInvoices(?\DateTimeInterface $lastMiraklUpdateTime, ?string $miraklShopId)
    {
        $filters = ['query' => []];

        if (null !== $lastMiraklUpdateTime) {
            $filters['query']['start_date'] = $lastMiraklUpdateTime->format(self::DATE_FORMAT);
        }

        if ('' !== $miraklShopId) {
            $filters['query']['shop'] = $miraklShopId;
        }
        $this->logger->info('[Mirakl API] Call to IV01 - fetch invoices');
        $response = $this->client->request('GET', '/api/invoices', $filters);

        return json_decode($response->getContent(), true)['invoices'];
    }

    // GET S20
    public function fetchShops(?array $shopIds, ?\DateTimeInterface $updatedAfter = null, bool $paginate = true)
    {
        $filters = ['query' => []];
        $filters['query']['paginate'] = $paginate ? 'true' : 'false';

        if (null !== $shopIds) {
            $filters['query']['shop_ids'] = implode(',', $shopIds);
        }
        if (null !== $updatedAfter) {
            $filters['query']['updated_since'] = $updatedAfter->format(self::DATE_FORMAT);
        }

        $this->logger->info('[Mirakl API] Call to S20 - fetch shops');
        $response = $this->client->request('GET', '/api/shops', $filters);

        return json_decode($response->getContent(), true)['shops'];
    }

    // PUT S07
    public function patchShops(array $patchedShops)
    {
        $response = $this->client->request('PUT', '/api/shops', [
            'json' => ['shops' => $patchedShops],
        ]);

        $this->logger->info('[Mirakl API] Call to S07 - patch shops');

        return json_decode($response->getContent(), true)['shop_returns'];
    }
}
