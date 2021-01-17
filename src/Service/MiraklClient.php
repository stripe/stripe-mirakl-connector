<?php

namespace App\Service;

use App\Exception\InvalidArgumentException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @codeCoverageIgnore
 */
class MiraklClient
{
    const ORDER_TYPE_PRODUCT = 'Product';
    const ORDER_TYPE_SERVICE = 'Service';

    const DATE_FORMAT = \DateTime::ISO8601;
    const DATE_FORMAT_INVALID_MESSAGE = 'Unexpected date format, expecting %s, input was %s';

    /**
     * @var HttpClientInterface
     */
    private $client;

    public function __construct(HttpClientInterface $miraklClient)
    {
        $this->client = $miraklClient;
    }

    private function parseResponse(ResponseInterface $response, array $attributes = [])
    {
        $res = json_decode($response->getContent(), true);
        foreach ($attributes as $attr) {
            if (isset($res[$attr])) {
                $res = $res[$attr];
            } else {
                break;
            }
        }

        return $res;
    }

    private function paginateByOffset(string $endpoint, array $attributes, array $params = [])
    {
        $options = [ 'query' => array_merge([ 'max' => 10 ], $params) ];

        $response = $this->client->request('GET', $endpoint, $options);
        $agg = $this->parseResponse($response, $attributes);
        while ($next = $this->getNextLink($response)) {
            $response = $this->client->request('GET', $next);
            $objects = $this->parseResponse($response, $attributes);
            if (empty($objects)) {
                break;
            }

            $agg = array_merge($agg, $objects);
        }

        return $agg;
    }

    private function getNextLink(ResponseInterface $response)
    {
        static $pattern = '/<([^>]+)>;\s*rel="([^"]+)"/';

        $h = $response->getHeaders();
        $links = isset($h['link'][0]) ? explode(',', $h['link'][0]) : [];
        foreach ($links as $link) {
            $res = preg_match($pattern, trim($link), $matches);
            if (false !== $res && 'next' === $matches[2]) {
                return $matches[1];
            }
        }

        return null;
    }

    private function paginateByPage(string $endpoint, array $attributes, array $params = [])
    {
        $options = [ 'query' => array_merge([ 'limit' => 10 ], $params) ];

        $response = $this->client->request('GET', $endpoint, $options);
        $agg = $this->parseResponse($response, $attributes);
        while ($next = $this->getNextPage($response)) {
            $options = [ 'query' => [ 'page_token' => $next ] ];
            $response = $this->client->request('GET', $endpoint, $options);
            $objects = $this->parseResponse($response, $attributes);
            if (empty($objects)) {
                break;
            }

            $agg = array_merge($agg, $objects);
        }

        return $agg;
    }

    private function getNextPage(ResponseInterface $response)
    {
        $body = $this->parseResponse($response);
        return $body['next_page_token'] ?? null;
    }

    // return [ order_id => order ]
    private function mapByOrderId(array $orders)
    {
        $orderIds = array_column($orders, 'order_id');
        return array_combine($orderIds, $orders);
    }

    // return [ id => order ]
    private function mapById(array $orders)
    {
        $orderIds = array_column($orders, 'id');
        return array_combine($orderIds, $orders);
    }

    // return [ order_commercial_id => [ order_id => order] ]
    private function mapByCommercialIdAndOrderId(array $orders)
    {
        $map = [];
        foreach ($orders as $order) {
            $commercialId = $order['commercial_id'] ?? $order['order_commercial_id'];
            if (!isset($map[$commercialId])) {
                $map[$commercialId] = [];
            }

            $map[$commercialId][$order['order_id']] = $order;
        }

        return $map;
    }

    // return [ order_commercial_id => [ id => order] ]
    private function mapByCommercialIdAndId(array $orders)
    {
        $map = [];
        foreach ($orders as $order) {
            $commercialId = $order['commercial_id'] ?? $order['order_commercial_id'];
            if (!isset($map[$commercialId])) {
                $map[$commercialId] = [];
            }

            $map[$commercialId][$order['id']] = $order;
        }

        return $map;
    }

    // return [ invoice_id => invoice ]
    private function mapByInvoiceId(array $invoices)
    {
        $invoiceIds = array_map('strval', array_column($invoices, 'invoice_id'));
        return array_combine($invoiceIds, $invoices);
    }

    // OR11
    public function listProductOrders()
    {
        return $this->mapByOrderId($this->paginateByOffset(
            '/api/orders',
            [ 'orders' ]
        ));
    }

    // OR11 by date
    public function listProductOrdersByDate(string $datetime)
    {
        return $this->mapByOrderId($this->paginateByOffset(
            '/api/orders',
            [ 'orders' ],
            [ 'start_date' => $datetime ]
        ));
    }

    // OR11 by order_id
    public function listProductOrdersById(array $orderIds)
    {
        return $this->mapByOrderId($this->paginateByOffset(
            '/api/orders',
            [ 'orders' ],
            [ 'order_ids' => implode(',', $orderIds) ]
        ));
    }

    // OR11 by commercial_id
    public function listProductOrdersByCommercialId(array $commercialIds)
    {
        return $this->mapByCommercialIdAndOrderId($this->paginateByOffset(
            '/api/orders',
            [ 'orders' ],
            [ 'commercial_ids' => implode(',', $commercialIds) ]
        ));
    }

    // PA11
    public function listProductPendingDebits()
    {
        return $this->mapByCommercialIdAndOrderId($this->paginateByOffset(
            '/api/payment/debit',
            [ 'orders', 'order' ]
        ));
    }

    // PA01
    public function validateProductPendingDebits(array $orders)
    {
        $this->client->request('PUT', '/api/payment/debit', [
            'json' => [ 'orders' => $orders ]
        ]);
    }

    // PA12
    public function listProductPendingRefunds()
    {
        return $this->mapByOrderId($this->paginateByOffset(
            '/api/payment/refund',
            [ 'orders', 'order' ]
        ));
    }

    // PA02
    public function validateProductPendingRefunds(array $refunds)
    {
        $this->client->request('PUT', '/api/payment/refund', [
            'json' => [ 'refunds' => $refunds ]
        ]);
    }

    // SOR11
    public function listServiceOrders()
    {
        return $this->mapById($this->paginateByPage(
            '/api/mms/orders',
            [ 'data' ]
        ));
    }

    // SOR11 by date
    public function listServiceOrdersByDate(string $datetime)
    {
        return $this->mapById($this->paginateByPage(
            '/api/mms/orders',
            [ 'data' ],
            [ 'date_created_start' => $datetime ]
        ));
    }

    // SOR11 by order_id
    public function listServiceOrdersById(array $orderIds)
    {
        return $this->mapById($this->paginateByPage(
            '/api/mms/orders',
            [ 'data' ],
            [ 'order_id' => $orderIds ]
        ));
    }

    // SOR11 by commercial_id
    public function listServiceOrdersByCommercialId(array $commercialIds)
    {
        return $this->mapByCommercialIdAndId($this->paginateByPage(
            '/api/mms/orders',
            [ 'data' ],
            [ 'commercial_order_id' => $commercialIds ]
        ));
    }

    // SPA11
    public function listServicePendingDebits()
    {
        return $this->mapByOrderId($this->paginateByPage(
            '/api/mms/debits',
            [ 'data' ]
        ));
    }

    // SPA01
    public function validateServicePendingDebits(array $orders)
    {
        $this->client->request('PUT', '/api/mms/debits', [
            'json' => [ 'orders' => $orders ]
        ]);
    }

    // SPA12
    public function listServicePendingRefunds()
    {
        return $this->mapByOrderId($this->paginateByPage(
            '/api/mms/refunds',
            [ 'data' ]
        ));
    }

    // SPA02
    public function validateServicePendingRefunds(array $refunds)
    {
        $this->client->request('PUT', '/api/mms/refunds', [
            'json' => [ 'refunds' => $refunds ]
        ]);
    }

    // IV01
    public function listInvoices()
    {
        return $this->mapByInvoiceId($this->paginateByOffset(
            '/api/invoices',
            [ 'invoices' ]
        ));
    }

    // IV01 by date
    public function listInvoicesByDate(string $datetime)
    {
        return $this->mapByInvoiceId($this->paginateByOffset(
            '/api/invoices',
            [ 'invoices' ],
            [ 'start_date' => $datetime ]
        ));
    }

    // IV01 by shop
    public function listInvoicesByShopId(int $shopId)
    {
        return $this->mapByInvoiceId($this->paginateByOffset(
            '/api/invoices',
            [ 'invoices' ],
            [ 'shop' => $shopId ]
        ));
    }

    // S20
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

        $response = $this->client->request('GET', '/api/shops', $filters);

        return $this->parseResponse($response, [ 'shops' ]);
    }

    // S07
    public function patchShops(array $patchedShops)
    {
        $response = $this->client->request('PUT', '/api/shops', [
            'json' => [ 'shops' => $patchedShops ],
        ]);

        return $this->parseResponse($response, [ 'shop_returns' ]);
    }

    // parse a date based on the format used by Mirakl
    public static function getDatetimeFromString(string $date): \DateTimeInterface
    {
        $dt = \DateTime::createFromFormat(self::DATE_FORMAT, $date);
        if (!$dt) {
            // Shouldn't happen unless Mirakl changed the date format
            throw new InvalidArgumentException(sprintf(
                self::DATE_FORMAT_INVALID_MESSAGE,
                self::DATE_FORMAT,
                $date
            ));
        }

        return $dt;
    }

    // parse a date based on the format used by Mirakl
    public static function getStringFromDatetime(\DateTimeInterface $date): string
    {
        return str_replace('+0000', 'Z', $date->format(self::DATE_FORMAT));
    }
}
