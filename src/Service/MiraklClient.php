<?php

namespace App\Service;

use App\Entity\MiraklProductOrder;
use App\Entity\MiraklProductPendingDebit;
use App\Entity\MiraklProductPendingRefund;
use App\Entity\MiraklServiceOrder;
use App\Entity\MiraklServicePendingDebit;
use App\Entity\MiraklServicePendingRefund;
use App\Entity\MiraklShop;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @codeCoverageIgnore
 */
class MiraklClient
{
    public const ORDER_TYPE_PRODUCT = 'Product';
    public const ORDER_TYPE_SERVICE = 'Service';

    public const DATE_FORMAT = \DateTime::ISO8601;
    public const DATE_FORMAT_INVALID_MESSAGE = 'Unexpected date format, expecting %s, input was %s';

    /**
     * @var HttpClientInterface
     */
    private $client;

    private $taxOrderPostfix;

    public function __construct(HttpClientInterface $miraklClient, string $taxOrderPostfix)
    {
        $this->client = $miraklClient;
        $this->taxOrderPostfix = $taxOrderPostfix;
    }

    private function get(string $endpoint, array $params = []): ResponseInterface
    {
        if ($params) {
            $endpoint .= '?'.$this->parseQueryParams($params);
        }

        return $this->client->request('GET', $endpoint);
    }

    private function put(string $endpoint, array $params): ResponseInterface
    {
        $options = ['json' => $params];

        return $this->client->request('PUT', $endpoint, $options);
    }

    private function parseQueryParams(array $params): ?string
    {
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        return preg_replace('/%5B[[:alnum:]_-]*%5D=/U', '=', $queryString);
    }

    private function parseResponse(ResponseInterface $response, string ...$path): array
    {
        $body = json_decode($response->getContent(), true);
        foreach ($path as $attr) {
            if (is_array($body) && isset($body[$attr])) {
                $body = $body[$attr];
            } else {
                break;
            }
        }

        return $body;
    }

    private function paginateByOffset(string $endpoint, array $params = [], string ...$path): array
    {
        $response = $this->get($endpoint, array_merge(['max' => 10], $params));
        $body = $this->parseResponse($response, ...$path);

        while ($next = $this->getNextLink($response)) {
            $response = $this->get($next);
            $objects = $this->parseResponse($response, ...$path);
            if (empty($objects)) {
                break;
            }

            $body = array_merge($body, $objects);
        }

        return $body;
    }

    private function paginateByPage(string $endpoint, array $params = [], string ...$path): array
    {
        $response = $this->get($endpoint, array_merge(['limit' => 10], $params));
        $body = $this->parseResponse($response, ...$path);

        while ($next = $this->getNextPage($response)) {
            $response = $this->get($endpoint, ['page_token' => $next]);
            $objects = $this->parseResponse($response, ...$path);
            if (empty($objects)) {
                break;
            }

            $body = array_merge($body, $objects);
        }

        return $body;
    }

    public function getNextLink(ResponseInterface $response): ?string
    {
        static $nexLinkPattern = '/<([^>]+)>;\s*rel="next"/';

        $linkHeader = $response->getHeaders()['link'][0] ?? '';
        if (1 === preg_match($nexLinkPattern, $linkHeader, $match)) {
            return $match[1];
        }

        return null;
    }

    private function getNextPage(ResponseInterface $response): ?string
    {
        $body = json_decode($response->getContent(), true);
        if (is_array($body) && isset($body['next_page_token'])) {
            return $body['next_page_token'];
        } else {
            return null;
        }
    }

    private function arraysToObjects(array $arrays, string $className): array
    {
        $objects = [];
        foreach ($arrays as $array) {
            $objects[] = new $className($array);
        }

        return $objects;
    }

    private function objectsToMap(array $objects, string $getter1, string $getter2 = null): array
    {
        $map = [];
        foreach ($objects as $object) {
            if ($getter2) {
                $map[$object->$getter1()] = $map[$object->$getter1()] ?? [];
                $map[$object->$getter1()][$object->$getter2()] = $object;
            } else {
                $map[$object->$getter1()] = $object;
            }
        }

        return $map;
    }

    private function arraysToMap(array $arrays, string $key): array
    {
        $keys = array_map('strval', array_column($arrays, $key));

        return array_combine($keys, $arrays);
    }

    // OR11
    public function listProductOrders(): array
    {
        $res = $this->paginateByOffset('/api/orders', [], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by date
    public function listProductOrdersByDate(string $datetime): array
    {
        $res = $this->paginateByOffset('/api/orders', ['start_date' => $datetime], 'orders');
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by order_id
    public function listProductOrdersById(array $orderIds): array
    {
        $orderIds = array_map([$this, 'removeTaxKeword'], $orderIds);
        $res = [];
        foreach (array_chunk($orderIds, 100) as $chunk) {
            $res = array_merge($res, $this->paginateByOffset('/api/orders', ['order_ids' => implode(',', $chunk)], 'orders'));
        }
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // OR11 by commercial_id
    public function listProductOrdersByCommercialId(array $commercialIds): array
    {
        $res = [];
        foreach (array_chunk($commercialIds, 100) as $chunk) {
            $res = array_merge($res, $this->paginateByOffset('/api/orders', ['commercial_ids' => implode(',', $chunk)], 'orders'));
        }
        $res = $this->arraysToObjects($res, MiraklProductOrder::class);

        return $this->objectsToMap($res, 'getCommercialId', 'getId');
    }

    // PA11
    public function listProductPendingDebits(): array
    {
        $res = $this->paginateByOffset('/api/payment/debit', [], 'orders', 'order');
        $res = $this->arraysToObjects($res, MiraklProductPendingDebit::class);

        return $this->objectsToMap($res, 'getCommercialId', 'getOrderId');
    }

    // PA01
    public function validateProductPendingDebits(array $orders): void
    {
        $this->put('/api/payment/debit', ['orders' => $orders]);
    }

    // PA12
    public function listProductPendingRefunds(): array
    {
        $res = $this->paginateByOffset('/api/payment/refund', [], 'orders', 'order');

        // Mirakl can return N refunds for one order, parse into 1 order <> 1 refund
        $pendingRefunds = [];
        foreach ($res as $order) {
            foreach ($order['order_lines']['order_line'] as $orderLine) {
                foreach ($orderLine['refunds']['refund'] as $orderRefund) {
                    $pendingRefund = $orderRefund; // id and amount
                    $pendingRefund['currency_iso_code'] = $order['currency_iso_code'];
                    $pendingRefund['order_commercial_id'] = $order['order_commercial_id'];
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
    public function validateProductPendingRefunds(array $refunds): void
    {
        $this->put('/api/payment/refund', ['refunds' => $refunds]);
    }

    // SOR11
    public function listServiceOrders(): array
    {
        $res = $this->paginateByPage('/api/mms/orders', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by date
    public function listServiceOrdersByDate(string $datetime): array
    {
        $res = $this->paginateByPage('/api/mms/orders', ['date_created_start' => $datetime], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by order_id
    public function listServiceOrdersById(array $orderIds): array
    {
        $orderIds = array_map([$this, 'removeTaxKeword'], $orderIds);
        $res = $this->paginateByPage('/api/mms/orders', ['order_id' => $orderIds], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);

        return $this->objectsToMap($res, 'getId');
    }

    // SOR11 by commercial_id
    public function listServiceOrdersByCommercialId(array $commercialIds): array
    {
        $res = $this->paginateByPage('/api/mms/orders', ['commercial_order_id' => $commercialIds], 'data');
        $res = $this->arraysToObjects($res, MiraklServiceOrder::class);

        return $this->objectsToMap($res, 'getCommercialId', 'getId');
    }

    // SPA11
    public function listServicePendingDebits(): array
    {
        $res = $this->paginateByPage('/api/mms/debits', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingDebit::class);

        return $this->objectsToMap($res, 'getOrderId');
    }

    // SPA11 by order ID
    public function listServicePendingDebitsByOrderIds(array $orderIds): array
    {
        $orderIds = array_map([$this, 'removeTaxKeword'], $orderIds);
        $res = $this->paginateByPage('/api/mms/debits', ['order_id' => $orderIds], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingDebit::class);

        return $this->objectsToMap($res, 'getOrderId');
    }

    // SPA01
    public function validateServicePendingDebits(array $orders): void
    {
        $this->put('/api/mms/debits', ['orders' => $orders]);
    }

    // SPA12
    public function listServicePendingRefunds(): array
    {
        $res = $this->paginateByPage('/api/mms/refunds', [], 'data');
        $res = $this->arraysToObjects($res, MiraklServicePendingRefund::class);

        return $this->objectsToMap($res, 'getId');
    }

    // SPA02
    public function validateServicePendingRefunds(array $refunds): void
    {
        $this->put('/api/mms/refunds', $refunds);
    }

    // IV01
    public function listInvoices(): array
    {
        $res = $this->paginateByOffset('/api/invoices', [], 'invoices');

        return $this->arraysToMap($res, 'invoice_id');
    }

    // IV01 by date
    public function listInvoicesByDate(string $datetime): array
    {
        $res = $this->paginateByOffset('/api/invoices', ['start_date' => $datetime], 'invoices');

        return $this->arraysToMap($res, 'invoice_id');
    }

    // IV01 by shop
    public function listInvoicesByShopId(int $shopId): array
    {
        $res = $this->paginateByOffset('/api/invoices', ['shop' => $shopId], 'invoices');

        return $this->arraysToMap($res, 'invoice_id');
    }

    // S20
    public function listShops(): array
    {
        $res = $this->paginateByOffset('/api/shops', ['domains' => 'PRODUCT,SERVICE'], 'shops');
        $res = $this->arraysToObjects($res, MiraklShop::class);

        return $this->objectsToMap($res, 'getId');
    }

    // S20 by date
    public function listShopsByDate(string $datetime): array
    {
        $res = $this->paginateByOffset('/api/shops', ['domains' => 'PRODUCT,SERVICE', 'updated_since' => $datetime], 'shops');
        $res = $this->arraysToObjects($res, MiraklShop::class);

        return $this->objectsToMap($res, 'getId');
    }

    // S20 by IDs
    public function listShopsByIds(array $shopIds): array
    {
        $res = $this->paginateByOffset('/api/shops', ['domains' => 'PRODUCT,SERVICE', 'shop_ids' => implode(',', $shopIds)], 'shops');
        $res = $this->arraysToObjects($res, MiraklShop::class);

        return $this->objectsToMap($res, 'getId');
    }

    // S07
    public function updateShopCustomField(int $shopId, string $code, string $value): void
    {
        $this->put('/api/shops', ['shops' => [[
            'shop_id' => $shopId,
            'shop_additional_fields' => [['code' => $code, 'value' => $value]],
        ]]]);
    }

    // S07
    public function updateShopKycStatus(int $shopId, string $status): void
    {
        $this->put('/api/shops', ['shops' => [[
            'shop_id' => $shopId,
            'kyc' => ['status' => $status],
        ]]]);
    }

    // S07
    public function updateShopKycStatusWithReason(array $updateShopsReqs): void
    {
        $this->put('/api/shops', ['shops' => $updateShopsReqs]);
    }

    public function getTransactionsForInvoce(string $invoiceId): array
    {
        $params['accounting_document_number'] = $invoiceId;
        $response = $this->get('/api/sellerpayment/transactions_logs', array_merge(['limit' => 150], $params));
        $body = $this->parseResponse($response, 'data');

        while ($next = $this->getNextPage($response)) {
            $response = $this->get('/api/sellerpayment/transactions_logs', ['page_token' => $next]);
            $objects = $this->parseResponse($response, 'data');
            if (empty($objects)) {
                break;
            }

            $body = array_merge($body, $objects);
        }

        $res_map = $this->arraysToMap($body, 'id');

        return $res_map;
    }

    // parse a date based on the format used by Mirakl
    public static function getDatetimeFromString(string $date): \DateTimeInterface
    {
        return new \DateTime($date);
    }

    // parse a date based on the format used by Mirakl
    public static function getStringFromDatetime(\DateTimeInterface $date): string
    {
        return $date->format(self::DATE_FORMAT);
    }

    private function removeTaxKeword(string $val): string
    {
        return str_replace($this->taxOrderPostfix, '', $val);
    }
}
