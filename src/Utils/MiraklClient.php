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

    private function getOrders(?array $query)
    {
        $filters = [
            'query' => [
              'customer_debited' => 'true',
              'limit' => 50
            ]
        ];

        if ($query) {
            $filters['query'] = array_merge($filters['query'], (array) $query);
        }

        return $this->getAllOrders($filters);
    }

    private function getAllOrders(?array $filters)
    {
        if (!$filters) {
            $filters = [];
        }

        $this->logger->info('[Mirakl API] Call to OR11 - fetch orders');
        $response = $this->client->request('GET', '/api/orders', $filters);

        return json_decode($response->getContent(), true)['orders'];
    }

    // GET OR11
    public function listOrders()
    {
        return $this->getOrders([]);
    }

    // GET OR11 by date
    public function listOrdersByDate(?\DateTimeInterface $datetime)
    {
        $dt = $datetime !== null ? $datetime->format(self::DATE_FORMAT) : null;
        return $this->getOrders([ 'start_update_date' => $dt ]);
    }

    // GET OR11 by id
    public function listOrdersById(?array $orderIds)
    {
        return $this->getOrders([ 'order_ids' => implode(',', (array) $orderIds) ]);
    }

    // GET OR11 by commercial_id (without prefilter)
    public function listCommercialOrdersById(?array $commercialOrderIds)
    {
        $filters = [
            'query' => [
              'commercial_ids' => implode(',', (array) $commercialOrderIds),
              'limit' => 50
            ]
        ];

        return $this->getAllOrders($filters);
    }

    // GET PA12
    public function listPendingRefunds()
    {
        $this->logger->info('[Mirakl API] Call to PA12 - List pending order refunds');
        $response = $this->client->request('GET', '/api/payment/refund');

        return json_decode($response->getContent(), true)['orders']['order'];
    }

    // GET PA11
    public function listPendingPayments()
    {
        $this->logger->info('[Mirakl API] Call to PA11 - List pending payments');
        $response = $this->client->request('GET', '/api/payment/debit');

        return json_decode($response->getContent(), true)['orders']['order'];
    }

    // PUT PA02
    public function validateRefunds(array $refunds)
    {
        $this->logger->info('[Mirakl API] Call to PA02 - validate refunds');
        $this->client->request('PUT', '/api/payment/refund', [
            'json' => ['refunds' => $refunds],
        ]);
    }

    // PUT PA01
    public function validatePayments(array $orders)
    {
        $this->logger->info('[Mirakl API] Call to PA01 - validate payments');
        $this->client->request('PUT', '/api/payment/debit', [
            'json' => ['orders' => $orders],
        ]);
    }

    private function getInvoices(?array $query)
    {
        $filters = ['query' => $query ?: []];

        $this->logger->info('[Mirakl API] Call to IV01 - fetch invoices');
        $response = $this->client->request('GET', '/api/invoices', $filters);

        return json_decode($response->getContent(), true)['invoices'];
    }

    // GET IV01
    public function listInvoices()
    {
        return $this->getInvoices([]);
    }

    // GET IV01 by date
    public function listInvoicesByDate(?\DateTimeInterface $datetime)
    {
        $dt = $datetime !== null ? $datetime->format(self::DATE_FORMAT) : null;
        return $this->getInvoices([ 'start_date' => $dt ]);
    }

    // GET IV01 by shop
    public function listInvoicesByShopId(?string $shopId)
    {
        return $this->getInvoices([ 'shop' => $shopId ]);
    }

    // GET S20
    public function fetchShops(?array $shopIds, ?\DateTimeInterface $updatedAfter = null, bool $paginate = true)
    {
        $filters = ['query' => []];
        $filters['query']['domains'] = 'PRODUCT,SERVICE';
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
