<?php

namespace App\Service;

use App\Entity\MiraklProductOrder;
use App\Entity\MiraklProductPendingDebit;
use App\Entity\MiraklProductPendingRefund;
use App\Entity\MiraklServiceOrder;
use App\Entity\MiraklServicePendingDebit;
use App\Entity\MiraklServicePendingRefund;
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

    private function parseResponse(ResponseInterface $response, string ...$path)
    {
        $body = json_decode($response->getContent(), true);
        foreach ($path as $attr) {
            if (isset($body[$attr])) {
                $body = $body[$attr];
            } else {
                break;
            }
        }

        return $body;
    }

    private function paginateByOffset(string $endpoint, array $params = [], string ...$path)
    {
        $options = [ 'query' => array_merge([ 'max' => 10 ], $params) ];

        $response = $this->client->request('GET', $endpoint, $options);
        $body = $this->parseResponse($response, ...$path);
        while ($next = $this->getNextLink($response)) {
            $response = $this->client->request('GET', $next);
            $objects = $this->parseResponse($response, ...$path);
            if (empty($objects)) {
                break;
            }

            $body = array_merge($body, $objects);
        }

        return $body;
    }

    private function paginateByPage(string $endpoint, array $params = [], string ...$path)
    {
        $options = [ 'query' => array_merge([ 'limit' => 10 ], $params) ];

        $response = $this->client->request('GET', $endpoint, $options);
        $body = $this->parseResponse($response, ...$path);
        while ($next = $this->getNextPage($response)) {
            $options = [ 'query' => [ 'page_token' => $next ] ];
            $response = $this->client->request('GET', $endpoint, $options);
            $objects = $this->parseResponse($response, ...$path);
            if (empty($objects)) {
                break;
            }

            $body = array_merge($body, $objects);
        }

        return $body;
    }

    public function getNextLink(ResponseInterface $response)
    {
        static $nexLinkPattern = '/<([^>]+)>;\s*rel="next"/';

        $linkHeader = $response->getHeaders()['link'][0] ?? '';
        if (1 === preg_match($nexLinkPattern, $linkHeader, $match)) {
            return $match[1];
        }

        return null;
    }

    private function getNextPage(ResponseInterface $response)
    {
        $body = json_decode($response->getContent(), true);
        return $body['next_page_token'] ?? null;
    }

    private function arraysToObjects(array $arrays, string $className)
    {
        $objects = [];
        foreach ($arrays as $array) {
            $objects[] = new $className($array);
        }

        return $objects;
    }

    private function objectsToMap(array $objects, string $getter1, string $getter2 = null)
    {
        $map = [];
        foreach ($objects as $object) {
            if ($getter2) {
                $map[$object->$getter1()] = $map[$object->$getter1()] ?? [] ;
                $map[$object->$getter1()][$object->$getter2()] = $object;
            } else {
                $map[$object->$getter1()] = $object;
            }
        }

        return $map;
    }

    private function arraysToMap(array $arrays, string $key)
    {
        $keys = array_map('strval', array_column($arrays, $key));
        return array_combine($keys, $arrays);
    }

    // OR11
    public function listProductOrders()
    {
        $res = $this->paginateByOffset('/api/orders', [], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by date
    public function listProductOrdersByDate(string $datetime)
    {
        $res = $this->paginateByOffset('/api/orders', [ 'start_date' => $datetime ], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by order_id
    public function listProductOrdersById(array $orderIds)
    {
        $res = $this->paginateByOffset('/api/orders', [ 'order_ids' => implode(',', $orderIds) ], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by commercial_id
    public function listProductOrdersByCommercialId(array $commercialIds)
    {
        $res = $this->paginateByOffset('/api/orders', [ 'commercial_ids' => implode(',', $commercialIds) ], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);
        return $this->objectsToMap($res, 'getCommercialId', 'getId');
    }

    // PA11
    public function listProductPendingDebits()
    {
        $res = $this->paginateByOffset('/api/payment/debit', [], 'orders', 'order');
        $res = $this->arraysToObjects($res, MiraklProductPendingDebit::class);
        return $this->objectsToMap($res, 'getCommercialId', 'getOrderId');
    }

    // PA01
    public function validateProductPendingDebits(array $orders)
    {
        $this->client->request('PUT', '/api/payment/debit', [ 'json' => [ 'orders' => $orders ] ]);
    }

    // PA12
    public function listProductPendingRefunds()
    {
        $res = $this->paginateByOffset('/api/payment/refund', [], 'orders', 'order');

        // Mirakl can return N refunds for one order, parse into 1 order <> 1 refund
        $pendingRefunds = [];
        foreach ($res as $order) {
            foreach ($order['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $orderRefund) {
                    $pendingRefund = $orderRefund; // id and amount
                    $pendingRefund['currency_iso_code'] = $order['currency_iso_code'];
                    $pendingRefund['order_id'] = $order['order_id'];
                    $pendingRefund['order_line_id'] = $orderLine['order_line_id'];

                    $pendingRefunds[] = $pendingRefund;
                }
            }
        }

        $res = $this->arraysToObjects($pendingRefunds, MiraklProductPendingRefund::class);
        return $this->objectsToMap($res, 'getId');
    }

    // PA02
    public function validateProductPendingRefunds(array $refunds)
    {
        $this->client->request('PUT', '/api/payment/refund', [ 'json' => [ 'refunds' => $refunds ] ]);
    }

    // SOR11
    public function listServiceOrders()
    {
        $res = $this->paginateByPage('/api/mms/orders', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by date
    public function listServiceOrdersByDate(string $datetime)
    {
        $res = $this->paginateByPage('/api/mms/orders', [ 'date_created_start' => $datetime ], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by order_id
    public function listServiceOrdersById(array $orderIds)
    {
        $res = $this->paginateByPage('/api/mms/orders', [ 'order_id' => $orderIds ], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);
        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by commercial_id
    public function listServiceOrdersByCommercialId(array $commercialIds)
    {
        $res = $this->paginateByPage('/api/mms/orders', [ 'commercial_order_id' => $commercialIds ], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);
        return $this->objectsToMap($res, 'getCommercialId', 'getId');
    }

    // SPA11
    public function listServicePendingDebits()
    {
        $res = $this->paginateByPage('/api/mms/debits', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingDebit::class);
        return $this->objectsToMap($res, 'getOrderId');
    }

    // SPA11 by order ID
    public function listServicePendingDebitsByOrderIds(array $orderIds)
    {
        $res = $this->paginateByPage('/api/mms/debits', [ 'order_id' => $orderIds ], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingDebit::class);
        return $this->objectsToMap($res, 'getOrderId');
    }

    // SPA01
    public function validateServicePendingDebits(array $orders)
    {
        $this->client->request('PUT', '/api/mms/debits', [ 'json' => [ 'orders' => $orders ] ]);
    }

    // SPA12
    public function listServicePendingRefunds()
    {
        $res = $this->paginateByPage('/api/mms/refunds', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingRefund::class);
        return $this->objectsToMap($res, 'getId');
    }

    // SPA02
    public function validateServicePendingRefunds(array $refunds)
    {
        $this->client->request('PUT', '/api/mms/refunds', [ 'json' => $refunds ]);
    }

    // IV01
    public function listInvoices()
    {
        $res = $this->paginateByOffset('/api/invoices', [], 'invoices');
        return $this->arraysToMap($res, 'invoice_id');
    }

    // IV01 by date
    public function listInvoicesByDate(string $datetime)
    {
        $res = $this->paginateByOffset('/api/invoices', [ 'start_date' => $datetime ], 'invoices');
        return $this->arraysToMap($res, 'invoice_id');
    }

    // IV01 by shop
    public function listInvoicesByShopId(int $shopId)
    {
        $res = $this->paginateByOffset('/api/invoices', [ 'shop' => $shopId ], 'invoices');
        return $this->arraysToMap($res, 'invoice_id');
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

        return $this->parseResponse($response, 'shops');
    }

    // S07
    public function patchShops(array $patchedShops)
    {
        $response = $this->client->request('PUT', '/api/shops', [
            'json' => [ 'shops' => $patchedShops ],
        ]);

        return $this->parseResponse($response, 'shop_returns');
    }

    // parse a date based on the format used by Mirakl
    public static function getDatetimeFromString(string $date): \DateTimeInterface
    {
        return new \DateTime($date);
    }

    // parse a date based on the format used by Mirakl
    public static function getStringFromDatetime(\DateTimeInterface $date): string
    {
        return str_replace('+0000', 'Z', $date->format(self::DATE_FORMAT));
    }
}
