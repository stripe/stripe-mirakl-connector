<?php

namespace App\Tests;

use App\Exception\InvalidArgumentException;
use App\Service\MiraklClient;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MiraklMockedHttpClient extends MockHttpClient
{
		public const MIRAKL_BASE_URL = 'https://mirakl.net';

		public const ORDER_BASIC = 'order_basic';
		public const ORDER_STATUS_WAITING_ACCEPTANCE = 'order_status_waiting_acceptance';
		public const ORDER_STATUS_WAITING_DEBIT = 'order_status_waiting_debit';
		public const ORDER_STATUS_WAITING_DEBIT_PAYMENT = 'order_status_waiting_debit_payment';
		public const ORDER_STATUS_STAGING = 'order_status_staging';
		public const ORDER_STATUS_SHIPPING = 'order_status_shipping';
		public const ORDER_STATUS_SHIPPED = 'order_status_shipped';
		public const ORDER_STATUS_TO_COLLECT = 'order_status_to_collect';
		public const ORDER_STATUS_RECEIVED = 'order_status_received';
		public const ORDER_STATUS_CLOSED = 'order_status_closed';
		public const ORDER_STATUS_REFUSED = 'order_status_refused';
		public const ORDER_STATUS_CANCELED = 'order_status_canceled';
		public const ORDER_STATUS_PARTIALLY_ACCEPTED = 'order_status_partially_accepted';
		public const ORDER_STATUS_PARTIALLY_REFUSED = 'order_status_partially_refused';
		public const ORDER_STATUS_WAITING_SCORING = 'order_status_waiting_scoring';
		public const ORDER_STATUS_ORDER_PENDING = 'order_status_order_pending';
		public const ORDER_STATUS_ORDER_ACCEPTED = 'order_status_order_accepted';
		public const ORDER_STATUS_ORDER_REFUSED = 'order_status_order_refused';
		public const ORDER_STATUS_ORDER_EXPIRED = 'order_status_order_expired';
		public const ORDER_STATUS_ORDER_CLOSED = 'order_status_order_closed';
		public const ORDER_STATUS_ORDER_CANCELLED = 'order_status_order_cancelled';
		public const ORDER_INVALID_AMOUNT = 'order_invalid_amount';
		public const ORDER_INVALID_SHOP = 'order_invalid_shop';
		public const ORDER_AMOUNT_NO_COMMISSION = 'order_no_commission';
		public const ORDER_AMOUNT_NO_TAX = 'order_no_tax';
		public const ORDER_AMOUNT_PARTIAL_TAX = 'order_partial_tax';
		public const ORDER_AMOUNT_NO_SALES_TAX = 'order_no_sales_tax';
		public const ORDER_AMOUNT_NO_SHIPPING_TAX = 'order_no_shipping_tax';

		public const ORDER_DATE_NO_NEW_ORDERS = '2019-01-01T00:00:00+0100';
		public const ORDER_DATE_3_NEW_ORDERS_1_READY = '2019-01-02T00:00:00+0100';
		public const ORDER_DATE_14_NEW_ORDERS_ALL_READY = '2100-01-01T00:00:00+0100';
		public const ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE = '2100-01-02T00:00:00+0100';
		public const ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_ID = 'order_last_of_page_2';

		public const ORDER_COMMERCIAL_ALL_VALIDATED = 'order_commercial_all_validated';
		public const ORDER_COMMERCIAL_NONE_VALIDATED = 'order_commercial_none_validated';
		public const ORDER_COMMERCIAL_PARTIALLY_VALIDATED = 'order_commercial_partially_validated';
		public const ORDER_COMMERCIAL_PARTIALLY_REFUSED = 'order_commercial_partially_refused';
		public const ORDER_COMMERCIAL_CANCELED = 'order_commercial_canceled';
		public const ORDER_COMMERCIAL_NOT_FOUND = 'order_commercial_not_found';

		public const PRODUCT_ORDER_PENDING_REFUND = 'product_order_pending_refund';
		public const SERVICE_ORDER_PENDING_REFUND = 'service_order_pending_refund';
		public const PRODUCT_ORDER_REFUND_BASIC = 1000;
		public const PRODUCT_ORDER_REFUND_VALIDATED = 1001;
		public const SERVICE_ORDER_REFUND_BASIC = 2000;
		public const SERVICE_ORDER_REFUND_VALIDATED = 2001;

		public const TRANSFER_BASIC = 'tr_basic';

		public const SHOP_BASIC = 1;
		public const SHOP_WITH_URL = 2;
		public const SHOP_NOT_READY = 99;
		public const SHOP_INVALID = 199;
		public const SHOP_NEW = 299;

		public const INVOICE_BASIC = 1;
		public const INVOICE_INVALID_AMOUNT = 2;
		public const INVOICE_INVALID_NO_SHOP = 3;
		public const INVOICE_INVALID_SHOP = 4;
		public const INVOICE_PAYOUT_ONLY = 5;
		public const INVOICE_SUBSCRIPTION_ONLY = 6;
		public const INVOICE_EXTRA_CREDIT_ONLY = 7;
		public const INVOICE_EXTRA_DEBIT_ONLY = 8;

		public const INVOICE_SHOP_BASIC = 1;
		public const INVOICE_SHOP_PAYOUT_ONLY = 2;
		public const INVOICE_SHOP_NOT_READY = 3;

		public const INVOICE_DATE_NO_NEW_INVOICES = '2019-01-01T00:00:00+0100';
		public const INVOICE_DATE_1_VALID = '2019-01-02T00:00:00+0100';
		public const INVOICE_DATE_1_INVALID_SHOP = '2019-01-03T00:00:00+0100';
		public const INVOICE_DATE_1_INVALID_NO_SHOP = '2019-01-04T00:00:00+0100';
		public const INVOICE_DATE_1_INVALID_AMOUNT = '2019-01-05T00:00:00+0100';
		public const INVOICE_DATE_1_PAYOUT_ONLY = '2019-01-06T00:00:00+0100';
		public const INVOICE_DATE_1_SUBSCRIPTION_ONLY = '2019-01-07T00:00:00+0100';
		public const INVOICE_DATE_1_EXTRA_CREDIT_ONLY = '2019-01-08T00:00:00+0100';
		public const INVOICE_DATE_1_EXTRA_DEBIT_ONLY = '2019-01-09T00:00:00+0100';
		public const INVOICE_DATE_3_INVOICES_ALL_INVALID = '2019-01-10T00:00:00+0100';
		public const INVOICE_DATE_3_INVOICES_1_VALID = '2019-01-11T00:00:00+0100';
		public const INVOICE_DATE_14_NEW_INVOICES_ALL_READY = '2019-02-01T00:00:00+0100';
		public const INVOICE_DATE_14_NEW_INVOICES_ALL_READY_END_DATE = '2019-02-02T00:00:00+0100';
		public const INVOICE_DATE_14_NEW_INVOICES_ALL_READY_END_ID = 1099;

    private $customFieldCode;

    public function __construct(string $customFieldCode)
    {
        $this->customFieldCode = $customFieldCode;

        $responseFactory = function ($method, $url, $options) {
						$path = parse_url($url, PHP_URL_PATH);
						parse_str(parse_url($url, PHP_URL_QUERY), $params);
		        $requestBody = json_decode($options['body'] ?? '', true);
						try {
								$response = $this->mockResponse($method, $path, $params, $requestBody);
								if ($nextPage = $this->getNextPageToken($path, $params)) {
										$response['next_page_token'] = $nextPage;
								}

								if ('PUT' === $method && empty($responseJson)) {
										return new MockResponse([], [ 'http_code' => 204 ]);
								} else {
										return new MockResponse(json_encode($response), [
												'http_code' => 200,
												'response_headers' => $this->getLinkHeader($path, $params)
										]);
								}
						} catch (InvalidArgumentException $e) {
								throw new \Exception("Unpexpected URL: {$url}. Method: {$method}.");
						} catch (\Exception $e) {
								return new MockResponse(
										[ 'message' => $e->getMessage() ],
										[ 'http_code' => $e->getCode() ]
								);
						}
        };

        parent::__construct($responseFactory, self::MIRAKL_BASE_URL);
    }

		private function isOffsetPagination($path, $params)
		{
				switch ($path) {
					case '/api/payment/debit':
					case '/api/payment/refund':
							return true;
					case '/api/orders':
							return self::ORDER_DATE_14_NEW_ORDERS_ALL_READY === ($params['start_date'] ?? '');
					case '/api/invoices':
							return self::INVOICE_DATE_14_NEW_INVOICES_ALL_READY === ($params['start_date'] ?? '');
					default:
							return false;
				}
    }

		private function getLinkHeader($path, $params)
		{
				if ($this->isOffsetPagination($path, $params) && 0 === ($params['offset'] ?? 0)) {
						$previous = self::MIRAKL_BASE_URL . $path . '?' . http_build_query($params);
						$params['offset'] = 10;
						$next = self::MIRAKL_BASE_URL . $path . '?' . http_build_query($params);
						$link = '<' . $previous . '>; rel="previous", <' . $next . '>; rel="next"';
						return [ 'Link' => $link ];
				}

				return null;
		}

		private function isSeekPagination($path, $params)
		{
				switch ($path) {
					case '/api/mms/debits':
					case '/api/mms/refunds':
							return true;
					case '/api/mms/orders':
							return self::ORDER_DATE_14_NEW_ORDERS_ALL_READY === ($params['date_created_start'] ?? '');
					default:
							return false;
				}
    }

		private function getNextPageToken($path, $params)
		{
				if ($this->isSeekPagination($path, $params) && !isset($params['page_token'])) {
						return 'random_page_token';
				}

				return null;
		}

		private function mockResponse(string $method, string $path, array $params, ?array $body): array
		{
				$offset = $params['offset'] ?? 0;
				$pageToken = $params['page_token'] ?? null;
				$page = ($offset === 0 && !$pageToken) ? 1 : 2;

				switch ($path) {
						case '/api/mms/orders':
								$isService = true;
						case '/api/orders':
								$isService = $isService ?? false;
								$key = $isService ? 'data' : 'orders';
								switch (true) {
										case isset($params['start_date']): // Product
										case isset($params['date_created_start']): // Service
										case null !== $pageToken: // Service
												if (null !== $pageToken) {
														$date = self::ORDER_DATE_14_NEW_ORDERS_ALL_READY;
												} else {
														$date = $params['start_date'] ?? $params['date_created_start'];
												}

												return [ $key => $this->mockOrdersByStartDate($isService, $date, $page) ];
										case isset($params['order_ids']): // Product
										case isset($params['order_id']): // Service
												$orderIds = isset($params['order_id']) ? explode(',', $params['order_id']) : explode(',', $params['order_ids']);
												return [ $key => $this->mockOrdersById($isService, $orderIds) ];
										case isset($params['commercial_ids']): // Product
										case isset($params['commercial_order_id']): // Service
												$commercialIds = isset($params['commercial_order_id']) ? explode(',', $params['commercial_order_id']) : explode(',', $params['commercial_ids']);
												return [ $key => $this->mockOrdersByCommercialId($isService, $commercialIds) ];
										default:
												return [ $key => [] ];
								}
								break;
						case '/api/mms/debits':
								$isService = true;
						case '/api/payment/debit':
								$isService = $isService ?? false;
								switch (true) {
										case isset($params['order_id']):
												return [ 'data' => $this->mockPendingDebitsByOrderIds(explode(',', $params['order_id'])) ];
										case 'GET' === $method:
												$pendingDebits = $this->mockPendingDebits($isService, $page);
												if ($isService) {
														return [ 'data' => $pendingDebits ];
												} else {
														return [ 'orders' => [ 'order' => $pendingDebits ] ];
												}
										case 'PUT' === $method:
												return $this->mockDebitValidation($body);
								}
								break;
						case '/api/mms/refunds':
								$isService = true;
						case '/api/payment/refund':
								$isService = $isService ?? false;
								switch ($method) {
										case 'GET':
												$pendingRefunds = $this->mockPendingRefunds($isService, $page);
												if ($isService) {
														return [ 'data' => $pendingRefunds ];
												} else {
														return [ 'orders' => [ 'order' => $pendingRefunds ] ];
												}
										case 'PUT':
												return $this->mockRefundValidation($body);
								}
								break;
						case '/api/shops':
								if (!isset($params['shop_ids']) || empty($params['shop_ids'])) {
										$params['shop_ids'] = [ self::SHOP_BASIC, self::SHOP_WITH_URL ];
								} elseif (!is_array($params['shop_ids'])) {
										$params['shop_ids'] = [ $params['shop_ids'] ];
								}

								switch ($method) {
									case 'GET':
											return [ 'shops' => $this->mockShops($params['shop_ids']) ];
									case 'PUT':
											return [ 'shop_returns' => $this->mockShops($params['shop_ids']) ];
								}
								break;
						case '/api/invoices':
								switch (true) {
										case isset($params['start_date']):
												$date = $params['start_date'];
												return [ 'invoices' => $this->mockInvoicesByStartDate($date, $page) ];
										case isset($params['shop']):
												return [ 'invoices' => $this->mockInvoicesByShop($params['shop']) ];
										default:
												return [ 'invoices' => [] ];
								}
								break;
				}

				throw new InvalidArgumentException();
		}

		private function mockOrdersByStartDate($isService, $date, $page)
		{
				switch ($date) {
						case self::ORDER_DATE_3_NEW_ORDERS_1_READY:
								if ($isService) {
										return $this->mockOrdersById($isService, [
												self::ORDER_STATUS_WAITING_SCORING,
												self::ORDER_STATUS_ORDER_PENDING,
												self::ORDER_STATUS_ORDER_REFUSED
										]);
								} else {
										return $this->mockOrdersById($isService, [
												self::ORDER_STATUS_STAGING,
												self::ORDER_STATUS_SHIPPING,
												self::ORDER_STATUS_REFUSED
										]);
								}
						case self::ORDER_DATE_14_NEW_ORDERS_ALL_READY:
								$ids = [];
								if (2 === $page) {
										for ($i = 10; $i < 13; $ids[] = 'random_order_' . ++$i);
										$ids[] = self::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_ID;
								} else {
										for ($i = 0; $i < 10; $ids[] = 'random_order_' . ++$i);
								}
								return $this->mockOrdersById($isService, $ids);
						case self::ORDER_DATE_NO_NEW_ORDERS:
						default:
								return [];
				}
    }

		private function mockOrdersByCommercialId($isService, $commercialIds)
		{
				$orders = [];
				foreach ($commercialIds as $commercialId) {
						switch ($commercialId) {
								case self::ORDER_COMMERCIAL_ALL_VALIDATED:
										$newOrders = $this->mockOrdersById($isService, [
												self::ORDER_STATUS_SHIPPED,
												self::ORDER_STATUS_RECEIVED,
										]);

										foreach ($newOrders as $i => $newOrder) {
												$newOrders[$i]['total_price'] *= 2;
										}

										$orders = array_merge($orders, $newOrders);
										break;
								case self::ORDER_COMMERCIAL_NONE_VALIDATED:
										$orders = array_merge($orders, $this->mockOrdersById($isService, [
												self::ORDER_STATUS_WAITING_ACCEPTANCE,
												self::ORDER_STATUS_WAITING_DEBIT
										]));
										break;
								case self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED:
										$orders = array_merge($orders, $this->mockOrdersById($isService, [
												self::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
												self::ORDER_STATUS_SHIPPING
										]));
										break;
								case self::ORDER_COMMERCIAL_PARTIALLY_REFUSED:
										$orders = array_merge($orders, $this->mockOrdersById($isService, [
												self::ORDER_STATUS_CLOSED,
												self::ORDER_STATUS_REFUSED
										]));
										break;
								case self::ORDER_COMMERCIAL_CANCELED:
										$orders = array_merge($orders, $this->mockOrdersById($isService, [
												self::ORDER_STATUS_CANCELED
										]));
										break;
								case self::ORDER_COMMERCIAL_NOT_FOUND:
								default:
										// No order
										break;
						}
				}

				return $orders;
    }

		private function mockOrdersById($isService, $orderIds)
		{
				$orders = [];
				foreach ($orderIds as $orderId) {
						$method = $isService ? 'getServiceOrder' : 'getProductOrder';
						$order = $this->$method($orderId);
						switch ($orderId) {
								case self::ORDER_STATUS_WAITING_ACCEPTANCE:
										$order = $this->$method($orderId, 'WAITING_ACCEPTANCE');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_NONE_VALIDATED;
										$order['customer_debited_date'] = null;
										break;
								case self::ORDER_STATUS_WAITING_DEBIT:
										$order = $this->$method($orderId, 'WAITING_DEBIT');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_NONE_VALIDATED;
										$order['customer_debited_date'] = null;
										break;
								case self::ORDER_STATUS_WAITING_DEBIT_PAYMENT:
										$order = $this->$method($orderId, 'WAITING_DEBIT_PAYMENT');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED;
										$order['customer_debited_date'] = null;
										break;
								case self::ORDER_STATUS_STAGING:
										$order = $this->getProductOrder($orderId, 'STAGING');
										$order['customer_debited_date'] = null;
										break;
								case self::ORDER_STATUS_SHIPPING:
										$order = $this->getProductOrder($orderId, 'SHIPPING');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED;
										break;
								case self::ORDER_STATUS_SHIPPED:
										$order = $this->getProductOrder($orderId, 'SHIPPED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_ALL_VALIDATED;
										break;
								case self::ORDER_STATUS_TO_COLLECT:
										$order = $this->getProductOrder($orderId, 'TO_COLLECT');
										break;
								case self::ORDER_STATUS_RECEIVED:
										$order = $this->getProductOrder($orderId, 'RECEIVED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_ALL_VALIDATED;
										break;
								case self::ORDER_STATUS_CLOSED:
										$order = $this->getProductOrder($orderId, 'CLOSED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_REFUSED;
										break;
								case self::ORDER_STATUS_REFUSED:
										$order = $this->getProductOrder($orderId, 'REFUSED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_REFUSED;
										$order['customer_debited_date'] = null;
										foreach ($order['order_lines'] as $i => $orderLine) {
												$order['total_price'] -= $orderLine['total_price'];
										}
										break;
								case self::ORDER_STATUS_CANCELED:
										$order = $this->getProductOrder($orderId, 'CANCELED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_CANCELED;
										$order['customer_debited_date'] = null;
										foreach ($order['order_lines'] as $i => $orderLine) {
												$order['order_lines'][$i]['cancelations'] = [[
														'amount' => $orderLine['total_price'],
														'shipping_taxes' => $orderLine['shipping_taxes'],
														'taxes' => $orderLine['taxes']
												]];
												$order['total_price'] -= $orderLine['total_price'];
												$order['order_lines'][$i]['total_price'] = 0;
												$order['order_lines'][$i]['shipping_taxes'] = [];
												$order['order_lines'][$i]['taxes'] = [];
										}
										break;
								case self::ORDER_STATUS_PARTIALLY_ACCEPTED:
										$order = $this->getProductOrder($orderId, 'WAITING_ACCEPTANCE', 'WAITING_DEBIT_PAYMENT');
										$order['customer_debited_date'] = null;
										break;
								case self::ORDER_STATUS_PARTIALLY_REFUSED:
										$order = $this->getProductOrder($orderId, 'SHIPPING', 'REFUSED');
										break;
								case self::ORDER_STATUS_WAITING_SCORING:
										$order = $this->getServiceOrder($orderId, 'WAITING_SCORING');
										break;
								case self::ORDER_STATUS_ORDER_PENDING:
										$order = $this->getServiceOrder($orderId, 'ORDER_PENDING');
										break;
								case self::ORDER_STATUS_ORDER_ACCEPTED:
										$order = $this->getServiceOrder($orderId, 'ORDER_ACCEPTED');
										break;
								case self::ORDER_STATUS_ORDER_REFUSED:
										$order = $this->getServiceOrder($orderId, 'ORDER_REFUSED');
										break;
								case self::ORDER_STATUS_ORDER_EXPIRED:
										$order = $this->getServiceOrder($orderId, 'ORDER_EXPIRED');
										break;
								case self::ORDER_STATUS_ORDER_CLOSED:
										$order = $this->getServiceOrder($orderId, 'ORDER_CLOSED');
										break;
								case self::ORDER_STATUS_ORDER_CANCELLED:
										$order = $this->getServiceOrder($orderId, 'ORDER_CANCELLED');
										break;
								case self::ORDER_INVALID_AMOUNT:
										if ($isService) {
												$order['commission']['amount_including_taxes'] = 100;
										} else {
												$order['total_commission'] = 100;
										}
										break;
								case self::ORDER_INVALID_SHOP:
										if ($isService) {
						        		$order['shop']['id'] = self::SHOP_NOT_READY;
										} else {
						        		$order['shop_id'] = self::SHOP_NOT_READY;
										}
										break;
								case self::ORDER_AMOUNT_NO_COMMISSION:
										if ($isService) {
												$order['commission']['amount_including_taxes'] = 0;
										} else {
												$order['total_commission'] = 0;
										}
										break;
								case self::ORDER_AMOUNT_NO_TAX:
										if ($isService) {
								        unset($order['price']['taxes']);
										} else {
								        unset($order['order_lines'][0]['shipping_taxes']);
								        unset($order['order_lines'][0]['taxes']);
								        unset($order['order_lines'][1]['shipping_taxes']);
								        unset($order['order_lines'][1]['taxes']);
										}
										break;
								case self::ORDER_AMOUNT_PARTIAL_TAX:
										if ($isService) {
								        unset($order['price']['taxes'][1]);
										} else {
								        unset($order['order_lines'][0]['shipping_taxes']);
								        unset($order['order_lines'][1]['taxes']);
										}
										break;
								case self::ORDER_AMOUNT_NO_SALES_TAX:
						        unset($order['order_lines'][0]['taxes']);
						        unset($order['order_lines'][1]['taxes']);
										break;
								case self::ORDER_AMOUNT_NO_SHIPPING_TAX:
						        unset($order['order_lines'][0]['shipping_taxes']);
						        unset($order['order_lines'][1]['shipping_taxes']);
										break;
								case self::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_ID:
										$key = $isService ? 'date_created' : 'created_date';
										$order[$key] = self::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE;
										break;
						}

						if (0 === strpos($orderId, self::SERVICE_ORDER_PENDING_REFUND)) {
								$orderId = substr($orderId, 0, strrpos($orderId, '-'));
								$refundIds = str_replace(self::SERVICE_ORDER_PENDING_REFUND . '_', '', $orderId);
								$order['refunds'] = [];
								foreach (explode('_', $refundIds) as $refundId) {
										$order['refunds'][] = [
												'id' => $refundId,
												'amount' => 12.34,
												'commission' => [
														'amount_including_taxes' => 1.23
												]
										];
								}
						}

						if (0 === strpos($orderId, self::PRODUCT_ORDER_PENDING_REFUND)) {
								$orderId = substr($orderId, 0, strrpos($orderId, '-'));
								$refundIds = str_replace(self::PRODUCT_ORDER_PENDING_REFUND . '_', '', $orderId);
								$order['order_lines'][0]['refunds'] = [];
								foreach (explode('_', $refundIds) as $refundId) {
										$order['order_lines'][0]['refunds'][] = [
												'id' => $refundId,
												'amount' => 12.34,
												'commission_total_amount' => 1.23
										];
								}
						}

						$orders[] = $order;
				}

				return $orders;
		}

    private function getProductOrder($orderId, $status = 'SHIPPING', $partialStatus = null)
    {
        return [
            'id' => rand(1, 1000),
            'commercial_id' => $orderId,
            'created_date' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'customer_debited_date' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'currency_iso_code' => 'EUR',
            'order_id' => $orderId,
            'order_state' => $status,
						'payment_workflow' => 'PAY_ON_ACCEPTANCE',
            'shop_id' => self::SHOP_BASIC,
            'last_updated_date' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'total_commission' => 3.99,
            'total_price' => 69.12,
            'order_lines' => [
                [
                    'order_line_id' => $orderId . '-1',
                    'order_line_state' => $status,
                    'total_price' => 12.34,
                    'shipping_taxes' => [
                        [ 'amount' => 1.12, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 1.34, 'code' => 'EXP_TAX' ]
                    ],
                    'taxes' => [
                        [ 'amount' => 1.56, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 1.78, 'code' => 'EXP_TAX' ]
                    ]
                ],
                [
                    'order_line_id' => $orderId . '-2',
                    'order_line_state' => $partialStatus ?? $status,
                    'total_price' => 56.78,
                    'shipping_taxes' => [
                        [ 'amount' => 2.12, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2.34, 'code' => 'EXP_TAX' ]
                    ],
                    'taxes' => [
                        [ 'amount' => 2.56, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2.78, 'code' => 'EXP_TAX' ]
                    ]
                ]
            ],
        ];
    }

    private function getServiceOrder($orderId, $status = 'ORDER_PENDING')
    {
        return [
            'id' => $orderId,
            'commercial_order_id' => $orderId,
            'date_created' => date_format(new \Datetime(), \Datetime::RFC3339_EXTENDED),
            'currency_code' => 'EUR',
            'state' => $status,
						'workflow' => [ 'type' => 'PAY_ON_ACCEPTANCE' ],
            'shop' => [ 'id' => self::SHOP_BASIC ],
            'commission' => [ 'amount_including_taxes' => 3.99 ],
            'price' => [
								'amount' => 12.34,
								'options' => [
										[ 'type' => 'BOOLEAN', 'amount' => 1.12 ],
										[ 'type' => 'VALUE_LIST', 'values' => [ [ 'amount' => 1.34 ] ] ]
								],
								'taxes' => [
										[ 'amount' => 1.56, 'code' => 'ECO_TAX' ],
										[ 'amount' => 1.78, 'code' => 'EXP_TAX' ]
								]
						]
        ];
    }

    private function mockPendingDebits($isService, $page)
    {
				$orders = [
						$this->getProductPendingDebit(
								self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED,
								self::ORDER_STATUS_WAITING_DEBIT_PAYMENT
						),
						$this->getProductPendingDebit(
								self::ORDER_COMMERCIAL_NONE_VALIDATED,
								self::ORDER_STATUS_WAITING_DEBIT
						)
				];

				return $orders;
    }

    private function mockPendingDebitsByOrderIds(array $orderIds)
    {
				$orders = [];
				foreach ($orderIds as $orderId) {
						switch ($orderId) {
							case self::ORDER_STATUS_WAITING_SCORING:
							case self::ORDER_STATUS_WAITING_ACCEPTANCE:
							case self::ORDER_STATUS_WAITING_DEBIT:
							case self::ORDER_STATUS_WAITING_DEBIT_PAYMENT:
							case self::ORDER_STATUS_ORDER_REFUSED:
							case self::ORDER_STATUS_ORDER_EXPIRED:
							case self::ORDER_STATUS_ORDER_CANCELLED:
									$order = $this->getServicePendingDebit($orderId, 'WAITING');
									break;
							default:
									$order = $this->getServicePendingDebit($orderId, 'OK');
									break;
						}

						$orders[] = $order;
				}

				return $orders;
    }

    private function getProductPendingDebit($commercialId, $orderId)
    {
        return [
            'currency_iso_code' => 'EUR',
            'order_commercial_id' => $commercialId,
            'order_id' => $orderId,
            'customer_id' => 'customer_basic',
						'payment_workflow' => 'PAY_ON_ACCEPTANCE',
            'shop_id' => self::SHOP_BASIC,
            'amount' => 12.34
        ];
    }

    private function getServicePendingDebit($orderId, $state)
    {
        return [
            'currency_code' => 'EUR',
            'order_id' => $orderId,
            'customer_id' => 'customer_basic',
            'state' => $state,
            'amount' => 12.34
        ];
    }

    private function mockDebitValidation($body)
    {
        $orderToValidate = $body['orders'][0]['order_id'];
        if ($orderToValidate === 'Order_00007-A') {
            throw new \Exception("Cannot update the order with id 'Order_00007-A' to status 'SHIPPING'. The order does not have the expected status 'WAITING_DEBIT_PAYMENT'.", 400);
        }

        return [];
    }

    private function mockPendingRefunds($isService, $page)
    {
				$method = $isService ? '' : '';
				$orders = [];
				if ($isService) {
						if ($page === 1) {
								for ($i = 0; $i < 10; $i++) {
										$refundId = self::SERVICE_ORDER_REFUND_BASIC + $i;
										$orders[] = $this->getServicePendingRefund($refundId);
								}
						} elseif ($page === 2) {
								for ($i = 0; $i < 4; $i++) {
										$refundId = self::SERVICE_ORDER_REFUND_BASIC + 10 + $i;
										$orders[] = $this->getServicePendingRefund($refundId);
								}
						}
				} else {
						if ($page === 1) {
								for ($i = 0; $i < 10; $i++) {
										$refundId = self::PRODUCT_ORDER_REFUND_BASIC + $i;
										$orders[] = $this->getProductPendingRefund($refundId);
								}
						} elseif ($page === 2) {
								for ($i = 0; $i < 4; $i++) {
										$refundId = self::PRODUCT_ORDER_REFUND_BASIC + 10 + $i;
										$orders[] = $this->getProductPendingRefund($refundId);
								}
						}
				}

				return $orders;
    }

    private function mockRefundValidation($body)
    {
				$id = $body['refunds'][0]['refund_id'] ?? $body[0]['id'];
        switch ($id) {
						case self::PRODUCT_ORDER_REFUND_BASIC:
						case self::SERVICE_ORDER_REFUND_BASIC:
								return [];
						case self::PRODUCT_ORDER_REFUND_VALIDATED:
						case self::SERVICE_ORDER_REFUND_VALIDATED:
								throw new \Exception("$id already validated", 400);;
        }
    }

    public static function getCommercialIdFromRefundId($orderType, $refundId)
    {
				if (MiraklClient::ORDER_TYPE_PRODUCT === $orderType) {
						return self::PRODUCT_ORDER_PENDING_REFUND . "_$refundId";
				} else {
						return self::SERVICE_ORDER_PENDING_REFUND . "_$refundId";
				}
    }

    public static function getOrderIdFromRefundId($orderType, $refundId)
    {
				return self::getCommercialIdFromRefundId($orderType, $refundId) . '-1';
    }

    private function getProductPendingRefund($refundId)
    {
				$commercialId = self::getCommercialIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId);
				$orderId = self::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_PRODUCT, $refundId);
        return [
            'currency_iso_code' => 'EUR',
            'order_commercial_id' => $commercialId,
            'order_id' => $orderId,
						'payment_workflow' => 'PAY_ON_ACCEPTANCE',
            'shop_id' => self::SHOP_BASIC,
            'amount' => 12.34,
            'order_lines' => [
            		'order_line' => [[
                    'order_line_id' => $orderId . '-1',
                    'order_line_amount' => 12.34,
                    'refunds' => [
		                    'refund' => [[
														'id' => $refundId,
														'amount' => 12.34
												]]
										]
                ]]
            ]
        ];
    }

    private function getServicePendingRefund($refundId)
    {
        return [
            'id' => $refundId,
            'order_id' => self::getOrderIdFromRefundId(MiraklClient::ORDER_TYPE_SERVICE, $refundId),
            'amount' => 12.34,
            'currency_code' => 'EUR'
        ];
    }

		private function mockShops($shopIds) {
				$shops = [];
				foreach ($shopIds as $shopId) {
						$shop = $this->getShop($shopId);
						switch ($shopId) {
								case self::SHOP_BASIC:
								case self::SHOP_NOT_READY:
								case self::SHOP_NEW:
										$shops[] = $shop;
										break;
								case self::SHOP_WITH_URL:
						        $shop['shop_additional_fields'][] = [
				                'code' => $this->customFieldCode,
				                'value' => 'https://onboarding',
				            ];
										$shops[] = $shop;
										break;
								case self::SHOP_INVALID:
								default:
										// Don't return anything
									break;
						}
				}

				return $shops;
		}

    private function getShop($shopId)
    {
        return [
						'shop_id' => $shopId,
						'shop_name' => 'Shop ' . $shopId,
            'currency_iso_code' => 'EUR',
            'date_created' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'is_professional' => false,
            'last_updated_date' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'shop_additional_fields' => [],
            'pro_details' => [
                'VAT_number' => null,
                'corporate_name' => null,
                'identification_number' => null,
                'tax_identification_number' => null,
            ],
            'contact_informations' => [
                'city' => 'Cupertino',
                'civility' => 'Ms',
                'country' => 'US',
                'email' => 'jane@pear.com',
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'street1' => '1 infinity Loop',
                'zip_code' => '12345',
            ]
        ];
    }

		private function mockInvoicesByStartDate($date, $page) {
				$invoices = [];
				switch ($date) {
						case self::INVOICE_DATE_1_VALID:
								$invoices = $this->mockInvoicesById([ self::INVOICE_BASIC ]);
								break;
						case self::INVOICE_DATE_1_INVALID_NO_SHOP:
								$invoices = $this->mockInvoicesById([ self::INVOICE_INVALID_NO_SHOP ]);
								break;
						case self::INVOICE_DATE_1_INVALID_SHOP:
								$invoices = $this->mockInvoicesById([ self::INVOICE_INVALID_SHOP ]);
								break;
						case self::INVOICE_DATE_1_INVALID_AMOUNT:
								$invoices = $this->mockInvoicesById([ self::INVOICE_INVALID_AMOUNT ]);
								break;
						case self::INVOICE_DATE_1_PAYOUT_ONLY:
								$invoices = $this->mockInvoicesById([ self::INVOICE_PAYOUT_ONLY ]);
								break;
						case self::INVOICE_DATE_1_SUBSCRIPTION_ONLY:
								$invoices = $this->mockInvoicesById([ self::INVOICE_SUBSCRIPTION_ONLY ]);
								break;
						case self::INVOICE_DATE_1_EXTRA_CREDIT_ONLY:
								$invoices = $this->mockInvoicesById([ self::INVOICE_EXTRA_CREDIT_ONLY ]);
								break;
						case self::INVOICE_DATE_1_EXTRA_DEBIT_ONLY:
								$invoices = $this->mockInvoicesById([ self::INVOICE_EXTRA_DEBIT_ONLY ]);
								break;
						case self::INVOICE_DATE_3_INVOICES_ALL_INVALID:
								$invoices = $this->mockInvoicesById([
										self::INVOICE_INVALID_AMOUNT,
										self::INVOICE_INVALID_NO_SHOP,
										self::INVOICE_INVALID_SHOP
								]);
								break;
						case self::INVOICE_DATE_3_INVOICES_1_VALID:
								$invoices = $this->mockInvoicesById([
										self::INVOICE_INVALID_SHOP,
										self::INVOICE_BASIC,
										self::INVOICE_INVALID_AMOUNT
								]);
								break;
						case self::INVOICE_DATE_14_NEW_INVOICES_ALL_READY:
								$ids = [];
								if (1 === $page) {
										for ($i = 1000; $i < 1010; $ids[] = ++$i);
								} else {
										for ($i = 1010; $i < 1013; $ids[] = ++$i);
										$ids[] = self::INVOICE_DATE_14_NEW_INVOICES_ALL_READY_END_ID;
								}
								$invoices = $this->mockInvoicesById($ids);

								// Helps when listing invoices by date a second time
								for ($i = 0, $j = count($invoices) - 1; $i < $j; $i++) {
										$invoices[$i]['date_created'] = $date;
								}
								break;
						case self::INVOICE_DATE_NO_NEW_INVOICES:
						default:
								break;
				}

				return $invoices;
    }

		private function mockInvoicesByShop($shopId) {
				switch ($shopId) {
						case self::INVOICE_SHOP_BASIC:
								return $this->mockInvoicesById([ self::INVOICE_BASIC ], $shopId);
						case self::INVOICE_SHOP_PAYOUT_ONLY:
								return $this->mockInvoicesById([ self::INVOICE_PAYOUT_ONLY ], $shopId);
						case self::INVOICE_SHOP_NOT_READY:
								return $this->mockInvoicesById([ self::INVOICE_INVALID_SHOP ], $shopId);
						default:
								return [];
				}
		}

		private function mockInvoicesById($invoiceIds, $shopId = self::SHOP_BASIC) {
				$invoices = [];
				foreach ($invoiceIds as $invoiceId) {
						$invoice = $this->getInvoice($invoiceId);
						switch ($invoiceId) {
							case self::INVOICE_INVALID_AMOUNT:
									foreach ($invoice['summary'] as $key => $value)
											$invoice['summary'][$key] = 0;
									break;
							case self::INVOICE_INVALID_NO_SHOP:
									unset($invoice['shop_id']);
									break;
							case self::INVOICE_INVALID_SHOP:
									$invoice['shop_id'] = self::SHOP_NOT_READY;
									break;
							case self::INVOICE_PAYOUT_ONLY:
									foreach ($invoice['summary'] as $key => $value)
											if ('amount_transferred' !== $key)
													$invoice['summary'][$key] = 0;
									break;
							case self::INVOICE_SUBSCRIPTION_ONLY:
									foreach ($invoice['summary'] as $key => $value)
											if ('total_subscription_incl_tax' !== $key)
													$invoice['summary'][$key] = 0;
									break;
							case self::INVOICE_EXTRA_CREDIT_ONLY:
									foreach ($invoice['summary'] as $key => $value)
											if ('total_other_credits_incl_tax' !== $key)
													$invoice['summary'][$key] = 0;
									break;
							case self::INVOICE_EXTRA_DEBIT_ONLY:
									foreach ($invoice['summary'] as $key => $value)
											if ('total_other_invoices_incl_tax' !== $key)
													$invoice['summary'][$key] = 0;
									break;
							case self::INVOICE_DATE_14_NEW_INVOICES_ALL_READY_END_ID:
									$invoice['date_created'] = self::INVOICE_DATE_14_NEW_INVOICES_ALL_READY_END_DATE;
									break;
						}

						$invoices[] = $invoice;
				}

				return $invoices;
    }

    private function getInvoice($invoiceId, $shopId = self::SHOP_BASIC)
    {
        return [
            'id' => rand(1, 1000),
            'currency_iso_code' => 'EUR',
            'date_created' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'end_time' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
            'invoice_id' => $invoiceId,
						'shop_id' => $shopId,
            'summary' => [
                'amount_transferred' => 12.34,
                'total_subscription_incl_tax' => 9.99,
                'total_other_credits_incl_tax' => 56.78,
                'total_other_invoices_incl_tax' => 98.76,
            ],
        ];
    }
}
