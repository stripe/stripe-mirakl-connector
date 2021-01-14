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
		public const ORDER_STATUS_STAGING = 'order_status_staging';
		public const ORDER_STATUS_WAITING_ACCEPTANCE = 'order_status_waiting_acceptance';
		public const ORDER_STATUS_WAITING_DEBIT = 'order_status_waiting_debit';
		public const ORDER_STATUS_WAITING_DEBIT_PAYMENT = 'order_status_waiting_debit_payment';
		public const ORDER_STATUS_SHIPPING = 'order_status_shipping';
		public const ORDER_STATUS_SHIPPED = 'order_status_shipped';
		public const ORDER_STATUS_TO_COLLECT = 'order_status_to_collect';
		public const ORDER_STATUS_RECEIVED = 'order_status_received';
		public const ORDER_STATUS_CLOSED = 'order_status_closed';
		public const ORDER_STATUS_REFUSED = 'order_status_refused';
		public const ORDER_STATUS_CANCELED = 'order_status_canceled';
		public const ORDER_STATUS_PARTIALLY_ACCEPTED = 'order_status_partially_accepted';
		public const ORDER_STATUS_PARTIALLY_REFUSED = 'order_status_partially_refused';
		public const ORDER_INVALID_DATE = 'order_invalid_date';
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

		public const ORDER_PENDING_REFUND = 'order_pending_refund';
		public const ORDER_REFUND_BASIC = 1000;
		public const ORDER_REFUND_VALIDATED = 1001;

		public const TRANSFER_BASIC = 'tr_basic';

		public const SHOP_BASIC = 1;
		public const SHOP_WITH_URL = 2;
		public const SHOP_NOT_READY = 99;
		public const SHOP_INVALID = 199;
		public const SHOP_NEW = 299;

		public const INVOICE_BASIC = 1;
		public const INVOICE_INVALID_AMOUNT = 2;
		public const INVOICE_INVALID_DATE = 3;
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
		public const INVOICE_DATE_1_INVALID_DATE = '2019-01-04T00:00:00+0100';
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
								$responseBody = json_encode($this->mockResponse($method, $path, $params, $requestBody));
								if ('PUT' === $method && empty($responseBody)) {
										return new MockResponse([], [ 'http_code' => 204 ]);
								} else {
										return new MockResponse($responseBody, [
												'http_code' => 200,
												'response_headers' => $this->getLinkHeader($path, $params)
										]);
								}
						} catch (InvalidArgumentException $e) {
								throw new \Exception('Unpexpected URL: ' . $url);
						} catch (\Exception $e) {
								return new MockResponse(
										[ 'message' => $e->getMessage() ],
										[ 'http_code' => $e->getCode() ]
								);
						}
        };

        parent::__construct($responseFactory, self::MIRAKL_BASE_URL);
    }

		private function isPaginated($path, $params)
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
				if ($this->isPaginated($path, $params) && 0 === ($params['offset'] ?? 0)) {
						$previous = self::MIRAKL_BASE_URL . $path . '?' . http_build_query($params);
						$params['offset'] = 10;
						$next = self::MIRAKL_BASE_URL . $path . '?' . http_build_query($params);
						$link = '<' . $previous . '>; rel="previous", <' . $next . '>; rel="next"';
						return [ 'Link' => $link ];
				}

				return null;
		}

		private function mockResponse(string $method, string $path, array $params, ?array $body): array
		{
				$offset = $params['offset'] ?? 0;
				switch ($path) {
						case '/api/orders':
								switch (true) {
										case isset($params['start_date']):
												$date = $params['start_date'];
												if (empty($date)) {
								            throw new \Exception("Invalid start date", 400);
												}
												return [ 'orders' => $this->mockOrdersByStartDate($date, $offset) ];
										case isset($params['order_ids']):
												$orderIds = explode(',', $params['order_ids']);
												return [ 'orders' => $this->mockOrdersById($orderIds) ];
										case isset($params['commercial_ids']):
												$commercialIds = explode(',', $params['commercial_ids']);
												return [ 'orders' => $this->mockOrdersByCommercialId($commercialIds) ];
										default:
												return [ 'orders' => [] ];
								}
								break;
						case '/api/payment/debit':
								switch ($method) {
										case 'GET':
												return [
														'orders' => [
																'order' => $this->mockPendingDebits($offset)
														]
												];
										case 'PUT':
												return $this->mockDebitValidation($body);
								}
								break;
						case '/api/payment/refund':
								switch ($method) {
										case 'GET':
												return [
														'orders' => [
																'order' => $this->mockPendingRefunds($offset)
														]
												];
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
												if (empty($date)) {
								            throw new \Exception("Invalid start date", 400);
												}
												return [ 'invoices' => $this->mockInvoicesByStartDate($date, $offset) ];
										case isset($params['shop']):
												return [ 'invoices' => $this->mockInvoicesByShop($params['shop']) ];
										default:
												return [ 'invoices' => [] ];
								}
								break;
				}

				throw new InvalidArgumentException();
		}

		private function mockOrdersByStartDate($date, $offset)
		{
				switch ($date) {
						case self::ORDER_DATE_3_NEW_ORDERS_1_READY:
								return $this->mockOrdersById([
										self::ORDER_STATUS_STAGING,
										self::ORDER_STATUS_SHIPPING,
										self::ORDER_STATUS_REFUSED
								]);
						case self::ORDER_DATE_14_NEW_ORDERS_ALL_READY:
								$ids = [];
								if (0 === $offset) {
										for ($i = 0; $i < 10; $ids[] = 'random_order_' . ++$i);
								} else {
										for ($i = 10; $i < 13; $ids[] = 'random_order_' . ++$i);
										$ids[] = self::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_ID;
								}
								return $this->mockOrdersById($ids);
						case self::ORDER_DATE_NO_NEW_ORDERS:
						default:
								return [];
				}
    }

		private function mockOrdersByCommercialId($commercialIds)
		{
				$orders = [];
				foreach ($commercialIds as $commercialId) {
						switch ($commercialId) {
								case self::ORDER_COMMERCIAL_ALL_VALIDATED:
										$newOrders = $this->mockOrdersById([
												self::ORDER_STATUS_SHIPPED,
												self::ORDER_STATUS_RECEIVED,
										]);
										$newOrders[self::ORDER_STATUS_SHIPPED]['total_price'] *= 2;
										$newOrders[self::ORDER_STATUS_RECEIVED]['total_price'] *= 2;
										$orders = array_merge($orders, $newOrders);
										break;
								case self::ORDER_COMMERCIAL_NONE_VALIDATED:
										$orders = array_merge($orders, $this->mockOrdersById([
												self::ORDER_STATUS_WAITING_DEBIT,
										]));
										break;
								case self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED:
										$orders = array_merge($orders, $this->mockOrdersById([
												self::ORDER_STATUS_WAITING_DEBIT_PAYMENT,
												self::ORDER_STATUS_SHIPPING,
										]));
										break;
								case self::ORDER_COMMERCIAL_PARTIALLY_REFUSED:
										$orders = array_merge($orders, $this->mockOrdersById([
												self::ORDER_STATUS_CLOSED,
												self::ORDER_STATUS_REFUSED,
										]));
										break;
								case self::ORDER_COMMERCIAL_CANCELED:
										$orders = array_merge($orders, $this->mockOrdersById([
												self::ORDER_STATUS_CANCELED,
										]));
										break;
						}
				}

				return $orders;
    }

		private function mockOrdersById($orderIds)
		{
				$orders = [];
				foreach ($orderIds as $orderId) {
						$order = $this->getOrder($orderId);
						switch ($orderId) {
								case self::ORDER_STATUS_STAGING:
										$order = $this->getOrder($orderId, 'STAGING');
										break;
								case self::ORDER_STATUS_WAITING_ACCEPTANCE:
										$order = $this->getOrder($orderId, 'WAITING_ACCEPTANCE');
										break;
								case self::ORDER_STATUS_WAITING_DEBIT:
										$order = $this->getOrder($orderId, 'WAITING_DEBIT');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_NONE_VALIDATED;
										break;
								case self::ORDER_STATUS_WAITING_DEBIT_PAYMENT:
										$order = $this->getOrder($orderId, 'WAITING_DEBIT_PAYMENT');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED;
										break;
								case self::ORDER_STATUS_SHIPPING:
										$order = $this->getOrder($orderId, 'SHIPPING');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED;
										break;
								case self::ORDER_STATUS_SHIPPED:
										$order = $this->getOrder($orderId, 'SHIPPED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_ALL_VALIDATED;
										break;
								case self::ORDER_STATUS_TO_COLLECT:
										$order = $this->getOrder($orderId, 'TO_COLLECT');
										break;
								case self::ORDER_STATUS_RECEIVED:
										$order = $this->getOrder($orderId, 'RECEIVED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_ALL_VALIDATED;
										break;
								case self::ORDER_STATUS_CLOSED:
										$order = $this->getOrder($orderId, 'CLOSED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_REFUSED;
										break;
								case self::ORDER_STATUS_REFUSED:
										$order = $this->getOrder($orderId, 'REFUSED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_PARTIALLY_REFUSED;
										break;
								case self::ORDER_STATUS_CANCELED:
										$order = $this->getOrder($orderId, 'CANCELED');
										$order['commercial_id'] = self::ORDER_COMMERCIAL_CANCELED;
										break;
								case self::ORDER_STATUS_PARTIALLY_ACCEPTED:
										$order = $this->getOrder($orderId, 'SHIPPING', 'WAITING_DEBIT_PAYMENT');
										break;
								case self::ORDER_STATUS_PARTIALLY_REFUSED:
										$order = $this->getOrder($orderId, 'SHIPPING', 'REFUSED');
										break;
								case self::ORDER_INVALID_DATE:
										$order['created_date'] = 'invalid';
										break;
								case self::ORDER_INVALID_AMOUNT:
						        $order['total_commission'] = '100';
										break;
								case self::ORDER_INVALID_SHOP:
						        $order['shop_id'] = self::SHOP_NOT_READY;
										break;
								case self::ORDER_AMOUNT_NO_COMMISSION:
						        $order['total_commission'] = 0;
										break;
								case self::ORDER_AMOUNT_NO_TAX:
						        unset($order['order_lines'][0]['shipping_taxes']);
						        unset($order['order_lines'][0]['taxes']);
						        unset($order['order_lines'][1]['shipping_taxes']);
						        unset($order['order_lines'][1]['taxes']);
										break;
								case self::ORDER_AMOUNT_PARTIAL_TAX:
						        unset($order['order_lines'][0]['shipping_taxes']);
						        unset($order['order_lines'][1]['taxes']);
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
										$order['created_date'] = self::ORDER_DATE_14_NEW_ORDERS_ALL_READY_END_DATE;
										break;
						}

						if (0 === strpos($orderId, self::ORDER_PENDING_REFUND)) {
								$refundIds = str_replace(self::ORDER_PENDING_REFUND . '_', '', $orderId);
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

    private function getOrder($orderId, $status = 'SHIPPING', $partialStatus = null)
    {
        return [
            'id' => rand(1, 1000),
            'commercial_id' => $orderId,
            'created_date' => date_format(new \Datetime(), MiraklClient::DATE_FORMAT),
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
                    'price' => 12.34,
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
                    'price' => 56.78,
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

    private function mockPendingDebits($offset)
    {
				$orders = [
						$this->getPendingDebit(
								self::ORDER_COMMERCIAL_PARTIALLY_VALIDATED,
								self::ORDER_STATUS_WAITING_DEBIT_PAYMENT
						),
						$this->getPendingDebit(
								self::ORDER_COMMERCIAL_NONE_VALIDATED,
								self::ORDER_STATUS_WAITING_DEBIT
						)
				];

				return $orders;
    }

    private function getPendingDebit($commercialId, $orderId)
    {
        return [
            'currency_iso_code' => 'EUR',
            'order_commercial_id' => $commercialId,
            'order_id' => $orderId,
						'payment_workflow' => 'PAY_ON_ACCEPTANCE',
            'shop_id' => self::SHOP_BASIC,
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

    private function mockPendingRefunds($offset)
    {
				$orders = [];
				switch ($offset) {
						case 0:
								for ($i = self::ORDER_REFUND_BASIC; $i < 1010; $i++) {
										$orders[] = $this->getPendingRefund($i);
								}
								break;
						case 10:
								for ($i = self::ORDER_REFUND_BASIC + 10; $i < 1014; $i++) {
										$orders[] = $this->getPendingRefund($i);
								}
								break;
				}

				return $orders;
    }

    private function mockRefundValidation($body)
    {
				$id = $body['refunds'][0]['refund_id'];
        switch ($id) {
						case self::ORDER_REFUND_BASIC:
								return [];
						case self::ORDER_REFUND_VALIDATED:
								throw new \Exception("$id already validated", 400);;
        }
    }

    public static function getOrderIdFromRefundId($refundId)
    {
				return self::ORDER_PENDING_REFUND . "_$refundId";
    }

    private function getPendingRefund($refundId)
    {
				$orderId = self::getOrderIdFromRefundId($refundId);
        return [
            'currency_iso_code' => 'EUR',
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

		private function mockInvoicesByStartDate($date, $offset) {
				$invoices = [];
				switch ($date) {
						case self::INVOICE_DATE_1_VALID:
								$invoices = $this->mockInvoicesById([ self::INVOICE_BASIC ]);
								break;
						case self::INVOICE_DATE_1_INVALID_SHOP:
								$invoices = $this->mockInvoicesById([ self::INVOICE_INVALID_SHOP ]);
								break;
						case self::INVOICE_DATE_1_INVALID_DATE:
								$invoices = $this->mockInvoicesById([ self::INVOICE_INVALID_DATE ]);
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
										self::INVOICE_INVALID_DATE,
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
								if (0 === $offset) {
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
							case self::INVOICE_INVALID_DATE:
									$invoice['date_created'] = '09-2019-24T14:00:40Z';
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
