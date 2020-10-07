<?php

namespace App\Tests;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class MiraklMockedHttpClient extends MockHttpClient
{
    private $customFieldCode;

    public function __construct(string $customFieldCode)
    {
        $this->customFieldCode = $customFieldCode;

        $responseFactory = function ($method, $url, $options) {
            $endpoint = str_replace('https://mirakl.net/api', '', $url);
            switch ($endpoint) {
                case '/orders?customer_debited=true':
                    return new MockResponse($this->getJsonMiraklOrders());
                case '/orders?customer_debited=true&start_update_date=2019-01-01T00%3A00%3A00%2B0000':
                case '/orders?customer_debited=true&start_update_date=2019-01-01T00%3A00%3A00%2B0100':
                    return new MockResponse($this->getJsonMiraklOrders());
                case '/orders?customer_debited=true&order_ids=order_5%2Corder_6':
                    return new MockResponse($this->getJsonSpecifiedMiraklOrders());
                case '/orders?customer_debited=true&order_ids=order_failed_transfer%2Cnew_order_1':
                    return new MockResponse($this->getJsonMiraklOrdersWithFailedTransfer());
                case '/orders?customer_debited=true&order_ids=order_created_transfer%2Cnew_order_2':
                    return new MockResponse($this->getJsonMiraklOrdersWithCreatedTransfer());
                case '/orders?customer_debited=true&order_ids=order_already_processed':
                    return new MockResponse($this->getJsonMiraklOrdersWithAlreadyProcessedTransfer());
                case '/orders?customer_debited=true&order_ids=order_1':
                    return new MockResponse($this->getJsonAlreadyExistingMiraklOrders());
                case '/orders?customer_debited=true&order_ids=order_8':
                    return new MockResponse($this->getJsonInvalidAmountMiraklOrders());
                case '/orders?customer_debited=true&order_ids=Order_Amount_Breakdown_0002-A%2COrder_Amount_Breakdown_0002-A':
                    return new MockResponse($this->getJsonForRefundOrders());
                case '/orders?customer_debited=true&order_ids=order_11':
                    return new MockResponse($this->getJsonOrderWithNegativeAmount('order_11'));
                case '/orders?customer_debited=true&order_ids=Order_66':
                    return new MockResponse($this->getJsonOrderForNoTransfer('Order_66'));
                case '/orders?customer_debited=true&order_ids=Order_51':
                    return new MockResponse($this->getJsonOrderForNoTransfer('Order_51'));
                case '/orders?customer_debited=true&order_ids=old_order_failed_transfer':
                    return new MockResponse(json_encode([
                        'orders' => [
                            $this->getMiraklOrder('old_order_failed_transfer'),
                        ],
                    ]));
                case '/orders?commercial_ids=Order_66%2COrder_42':
                    return new MockResponse($this->getJsonOrdersWithCommercialId());
                case '/shops':
                    return new MockResponse($this->getReturnJsonShops());
                case '/shops?paginate=true&shop_ids=1':
                case '/shops?paginate=true&shop_ids=11':
                case '/shops?paginate=true&shop_ids=13':
                case '/shops?paginate=true&shop_ids=123':
                case '/shops?paginate=true&shop_ids=2000':
                    return new MockResponse($this->getJsonShop());
                case '/shops?paginate=false':
                case '/shops?paginate=false&updated_since=2019-10-01T00%3A00%3A00%2B0000':
                    return new MockResponse($this->getJsonShops());
                case '/invoices':
                    return new MockResponse($this->getJsonMiraklInvoices());
                case '/invoices?start_date=2019-01-01T00%3A00%3A00%2B0000':
                case '/invoices?start_date=2019-01-01T00%3A00%3A00%2B0100':
                    return new MockResponse($this->getJsonMiraklInvoices());
                case '/invoices?shop=1':
                    return new MockResponse($this->getSpecifiedJsonMiraklInvoices());
                case '/invoices?shop=2':
                    return new MockResponse($this->getJsonMiraklInvoicesWithFailedPayout());
                case '/invoices?shop=3':
                    return new MockResponse($this->getJsonAlreadyExistingMiraklInvoices());
                case '/invoices?shop=4':
                    return new MockResponse($this->getJsonFailedTransferMiraklInvoices());
                case '/invoices?shop=5':
                    return new MockResponse($this->getJsonAlreadyExistingMiraklInvoices2());
                case '/invoices?shop=6':
                    return new MockResponse($this->getJsonCreateFailedTransfert());
                case '/payment/debit':
                    switch ($method) {
                        case 'GET':
                            return new MockResponse($this->getPendingPayment());
                        case 'PUT':
                            return $this->mockPendingValidation($options);
                    }
                    return new MockResponse($this->getEmptyJson());
                case '/payment/refund':
                    switch ($method) {
                        case 'GET':
                            return new MockResponse($this->getPendingRefunds());
                        case 'PUT':
                            return $this->mockRefundValidation($options);
                    }
                    // no break
                default:
                    return new MockResponse($this->getEmptyJson());
            }
        };

        parent::__construct($responseFactory, 'https://mirakl.net');
    }

    private function mockRefundValidation($options)
    {
        $body = json_decode($options['body'], true);
        $refundToValidate = $body['refunds'][0]['refund_id'];
        if ($refundToValidate == '1110') {
            return new MockResponse(['message' => 'Generated Error'], ['http_code' => 400]);
        } elseif ($refundToValidate == '1107') {
            return new MockResponse(['message' => 'cannot be processed because it is in state REFUNDED'], ['http_code' => 400]);
        } else {
            return new MockResponse(json_encode([]), ['http_code' => 204]);
        }
    }

    private function mockPendingValidation($options)
    {
        $body = json_decode($options['body'], true);
        $orderToValidate = $body['orders'][0]['order_id'];

        if ($orderToValidate === 'Order_00007-A') {
            return new MockResponse(['message' => "Cannot update the order with id 'Order_00007-A' to status 'SHIPPING'. The order does not have the expected status 'WAITING_DEBIT_PAYMENT'."], ['http_code' => 400]);
        }

        return new MockResponse(json_encode([]), ['http_code' => 204]);
    }

    private function getMiraklOrder($orderId)
    {
        return [
            'id' => 1,
            'order_id' => $orderId,
            'commercial_id' => $orderId,
            'shop_id' => '1',
            'total_price' => 24,
            'order_state' => 'RECEIVED',
            'order_lines' => [
                [
                    'order_line_id' => $orderId . '-1',
                    'order_line_state' => 'RECEIVED',
                    'price' => 24,
                    'shipping_taxes' => [
                        [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                    ],
                    'taxes' => [
                        [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                    ]
                ],
                [
                    'order_line_id' => $orderId . '-2',
                    'order_line_state' => 'REFUSED',
                    'price' => 12,
                    'shipping_taxes' => [
                        [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                    ],
                    'taxes' => [
                        [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                        [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                    ]
                ]
            ],
            'total_commission' => 5,
            'transaction_number' => 'ch_transaction_1',
            'currency_iso_code' => 'eur',
            'last_updated_date' => '2019-09-24T14:00:40Z',
        ];
    }

    private function getMiraklShop($shopId, $shopName, $additionalField = null)
    {
        $baseShop = [
            'contact_informations' => [
                'city' => 'Cupertino',
                'civility' => 'Ms',
                'country' => 'US',
                'email' => 'jane@pear.com',
                'fax' => null,
                'firstname' => 'Jane',
                'lastname' => 'Doe',
                'phone' => null,
                'phone_secondary' => null,
                'state' => null,
                'street1' => '1 infinity Loop',
                'street2' => null,
                'web_site' => null,
                'zip_code' => '12345',
            ],
            'currency_iso_code' => 'EUR',
            'date_created' => '2019-09-23T07:59:11Z',
            'is_professional' => false,
            'last_updated_date' => '2019-09-28T07:45:40Z',
            'pro_details' => [
                'VAT_number' => null,
                'corporate_name' => null,
                'identification_number' => null,
                'tax_identification_number' => null,
            ],
            'shop_additional_fields' => [],
            'shop_id' => $shopId,
            'shop_name' => $shopName,
        ];

        if ($additionalField) {
            $baseShop['shop_additional_fields'][] = [
                'code' => $this->customFieldCode,
                'value' => $additionalField,
            ];
        }

        return $baseShop;
    }

    private function getJsonMiraklOrders()
    {
        return json_encode([
            'orders' => [
                $this->getMiraklOrder('order_4'),
            ],
        ]);
    }

    private function getJsonSpecifiedMiraklOrders()
    {
        return json_encode([
            'orders' => [
                $this->getMiraklOrder('order_5'),
                $this->getMiraklOrder('order_6'),
            ],
        ]);
    }

    private function getJsonMiraklOrdersWithFailedTransfer()
    {
        return json_encode([
            'orders' => [
                $this->getMiraklOrder('order_failed_transfer'),
                $this->getMiraklOrder('new_order_1'),
            ],
        ]);
    }

    private function getJsonMiraklOrdersWithCreatedTransfer()
    {
        return json_encode([
            'orders' => [
                $this->getMiraklOrder('order_created_transfer'),
                $this->getMiraklOrder('new_order_2'),
            ],
        ]);
    }

    private function getJsonMiraklOrdersWithAlreadyProcessedTransfer()
    {
        return json_encode([
            'orders' => [
                $this->getMiraklOrder('order_already_processed'),
            ],
        ]);
    }

    private function getJsonAlreadyExistingMiraklOrders()
    {
        $alreadyExistingMiraklOrder = $this->getMiraklOrder('order_1');

        return json_encode([
            'orders' => [
                $alreadyExistingMiraklOrder,
            ],
        ]);
    }

    private function getJsonInvalidAmountMiraklOrders()
    {
        $invalidAmountMiraklOrder = $this->getMiraklOrder('order_8');
        $invalidAmountMiraklOrder['total_price'] = '10';
        $invalidAmountMiraklOrder['total_commission'] = '20';

        return json_encode([
            'orders' => [
                $invalidAmountMiraklOrder,
            ],
        ]);
    }

    private function getEmptyJson()
    {
        return json_encode([
            'orders' => [],
            'shops' => [],
            'invoices' => [],
        ]);
    }

    private function getJsonShops()
    {
        return json_encode([
            'shops' => [
                $this->getMiraklShop(2000, 'Shop 1'),
                $this->getMiraklShop(2001, 'Shop 2', 'https://onboarding'),
                $this->getMiraklShop(2, 'Mapped Shop'),
            ],
        ]);
    }

    private function getJsonShop()
    {
        return json_encode([
            'shops' => [
                $this->getMiraklShop(2000, 'Shop 1'),
            ],
        ]);
    }

    private function getReturnJsonShops()
    {
        return json_encode([
            'shop_returns' => [
                $this->getMiraklShop(2000, 'Shop 1'),
                $this->getMiraklShop(2001, 'Shop 2'),
            ],
        ]);
    }

    private function getMiraklInvoice($shopId, $invoiceId)
    {
        return [
            'invoice_id' => $invoiceId,
            'shop_id' => $shopId,
            'summary' => [
                'amount_transferred' => 12.34,
                'total_subscription_incl_tax' => 9.99,
                'total_other_credits_incl_tax' => 56.78,
                'total_other_invoices_incl_tax' => 98.76,
            ],
            'currency_iso_code' => 'eur',
            'end_time' => '2019-09-24T14:00:40Z',
        ];
    }

    private function getMiraklInvoiceZeroAmount($shopId, $invoiceId)
    {
        return [
            'invoice_id' => $invoiceId,
            'shop_id' => $shopId,
            'summary' => [
                'amount_transferred' => 0,
                'total_subscription_incl_tax' => 0,
                'total_other_credits_incl_tax' => 0,
                'total_other_invoices_incl_tax' => 0,
            ],
            'currency_iso_code' => 'eur',
            'end_time' => '2019-09-24T14:00:40Z',
        ];
    }

    private function getMiraklInvoiceBadEndtime($shopId, $invoiceId)
    {
        return [
            'invoice_id' => $invoiceId,
            'shop_id' => $shopId,
            'summary' => [
                'amount_transferred' => 12.34,
                'total_subscription_incl_tax' => 9.99,
                'total_other_credits_incl_tax' => 56.78,
                'total_other_invoices_incl_tax' => 98.76,
            ],
            'currency_iso_code' => 'eur',
            'end_time' => '09-2019-24T14:00:40Z',
        ];
    }

    private function getMiraklInvoiceBadShopId($shopId, $invoiceId)
    {
        return [
            'invoice_id' => $invoiceId,
            'shop_id' => $shopId.'-42.123',
            'summary' => [
                'amount_transferred' => 12.34,
                'total_subscription_incl_tax' => 9.99,
                'total_other_credits_incl_tax' => 56.78,
                'total_other_invoices_incl_tax' => 98.76,
            ],
            'currency_iso_code' => 'eur',
            'end_time' => '09-2019-24T14:00:40Z',
        ];
    }

    private function getJsonMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(1, 4),
            ],
        ]);
    }

    private function getSpecifiedJsonMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(1, 5),
            ],
        ]);
    }

    private function getJsonMiraklInvoicesWithFailedPayout()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(2, 6),
                $this->getMiraklInvoice(2, 7),
            ],
        ]);
    }

    private function getJsonAlreadyExistingMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(3, 8),
                $this->getMiraklInvoice(3, 9),
            ],
        ]);
    }

    private function getJsonFailedTransferMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(3, 10),
                $this->getMiraklInvoice(3, 11),
            ],
        ]);
    }

    private function getJsonAlreadyExistingMiraklInvoices2()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(3, 12),
                $this->getMiraklInvoice(3, 13),
            ],
        ]);
    }

    private function getJsonCreateFailedTransfert()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoiceZeroAmount(3, 14),
                $this->getMiraklInvoiceBadEndtime(3, 15),
                $this->getMiraklInvoiceBadShopId(3, 16),
            ],
        ]);
    }

    private function getPendingRefunds()
    {
        return json_encode([
            'orders' => [
              'order' => [
                [
                  'amount' => 100,
                  'currency_iso_code' => 'USD',
                  'customer_id' => 'Customer_id_001',
                  'order_commercial_id' => 'Order_Amount_Breakdown_0002',
                  'order_id' => 'Order_Amount_Breakdown_0002-A',
                  'order_lines' => [
                    'order_line' => [
                      [
                        'offer_id' => '2130',
                        'order_line_amount' => 20,
                        'order_line_id' => 'Order_Amount_Breakdown_0002-A-1',
                        'order_line_quantity' => 10,
                        'refunds' => [
                          'refund' => [
                            [
                              'amount' => 10,
                              'id' => '6666',
                            ],
                            [
                              'amount' => 5,
                              'id' => '1105',
                            ],
                          ],
                        ],
                      ],
                      [
                        'offer_id' => '2130',
                        'order_line_amount' => 80,
                        'order_line_id' => 'Order_Amount_Breakdown_0002-A-2',
                        'order_line_quantity' => 10,
                        'refunds' => [
                          'refund' => [
                            [
                              'amount' => 8,
                              'id' => '1106',
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                  'payment_workflow' => 'PAY_ON_ACCEPTANCE',
                  'shop_id' => '2000',
                ],
                [
                  'amount' => 100,
                  'currency_iso_code' => 'EUR',
                  'customer_id' => 'Customer_id_001',
                  'order_commercial_id' => 'Order_Amount_Breakdown_0003',
                  'order_id' => 'Order_Amount_Breakdown_0002-A',
                  'order_lines' => [
                    'order_line' => [
                      [
                        'offer_id' => '1599',
                        'order_line_amount' => 100,
                        'order_line_id' => 'Order_Amount_Breakdown_0003-A-1',
                        'order_line_quantity' => 1,
                        'refunds' => [
                          'refund' => [
                            [
                              'amount' => 10,
                              'id' => '1199',
                            ],
                          ],
                        ],
                      ],
                    ],
                  ],
                  'payment_workflow' => 'PAY_ON_ACCEPTANCE',
                  'shop_id' => '2002',
                ],
              ],
            ],
            'total_count' => 2,
          ]);
    }

    private function getJsonForRefundOrders()
    {
        return json_encode([
            'orders' => [
                    [
                        'amount' => 100,
                        'currency_iso_code' => 'USD',
                        'customer_id' => 'Customer_id_001',
                        'order_commercial_id' => 'Order_Amount_Breakdown_0002',
                        'order_id' => 'Order_Amount_Breakdown_0002-A',
                        'order_lines' => [
                            [
                                'offer_id' => '2130',
                                'total_price' => 20,
                                'total_commission' => 10,
                                'order_line_id' => 'Order_Amount_Breakdown_0002-A-1',
                                'order_line_quantity' => 10,
                            ],
                            [
                                'offer_id' => '2130',
                                'total_price' => 80,
                                'total_commission' => 10,
                                'order_line_id' => 'Order_Amount_Breakdown_0002-A-2',
                                'order_line_quantity' => 10,
                            ],
                        ],
                        'payment_workflow' => 'PAY_ON_ACCEPTANCE',
                        'shop_id' => '2000',
                    ],
                    [
                        'amount' => 100,
                        'currency_iso_code' => 'EUR',
                        'customer_id' => 'Customer_id_001',
                        'order_commercial_id' => 'Order_Amount_Breakdown_0003',
                        'order_id' => 'Order_Amount_Breakdown_0002-A',
                        'order_lines' => [
                            [
                                'offer_id' => '1599',
                                'total_price' => 100,
                                'total_commission' => 10,
                                'order_line_id' => 'Order_Amount_Breakdown_0003-A-1',
                                'order_line_quantity' => 1,
                            ],
                        ],
                        'payment_workflow' => 'PAY_ON_ACCEPTANCE',
                        'shop_id' => '2002',
                    ],
            ],
            'total_count' => 2,
        ]);
    }

    private function getPendingPayment()
    {
        return json_encode([
            'orders' => [
                'order' => [
                    [
                        'order_state' => 'SHIPPING',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "order_commercial_id" => "Order_66",
                        "commercial_id" => "Order_66",
                        "order_id" => "Order_66-A",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2015",
                                    "order_line_amount" => 173.00,
                                    "order_line_id" => "Order_66-A-1",
                                    "order_line_quantity" => 3,
                                    'order_line_state' => 'REFUSED',
                                ],
                                [
                                    "offer_id" => "2017",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_66-A-2",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2015"
                    ],
                    [
                        'order_state' => 'RECEIVED',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "order_commercial_id" => "Order_66",
                        "order_id" => "Order_66-B",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2016",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_66-b-2",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2016"
                    ],
                    [
                        'order_state' => 'NOT_VALID',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "order_commercial_id" => "Order_42",
                        "order_id" => "Order_42-A",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2016",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_42-a-1",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2016"
                    ],
                    [
                        'order_state' => 'SHIPPING',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "order_commercial_id" => "Order_42",
                        "order_id" => "Order_42-B",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2016",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_42-a-1",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2016"
                    ],
                ],
            ],
            'total_count' => 1,
        ]);
    }

    private function getJsonOrdersWithCommercialId()
    {
        return json_encode([
            'orders' => [
                    [
                        'order_state' => 'SHIPPING',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "commercial_id" => "Order_66",
                        "order_id" => "Order_66-A",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2015",
                                    "order_line_amount" => 173.00,
                                    "order_line_id" => "Order_66-A-1",
                                    "order_line_quantity" => 3,
                                    'order_line_state' => 'REFUSED',
                                ],
                                [
                                    "offer_id" => "2017",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_66-A-2",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2015"
                    ],
                    [
                        'order_state' => 'RECEIVED',
                        "total_price" => 330.00,
                        "amount" => 330.00,
                        "currency_iso_code" => "EUR",
                        "customer_id" => "Customer_id_001",
                        "commercial_id" => "Order_66",
                        "order_id" => "Order_66-B",
                        "order_lines" => [
                            "order_line" => [
                                [
                                    "offer_id" => "2016",
                                    "order_line_amount" => 330.00,
                                    "order_line_id" => "Order_66-b-2",
                                    "order_line_quantity" => 5,
                                    'order_line_state' => 'SHIPPING',
                                ]
                            ]
                        ],
                        "shop_id" => "2016"
                    ],
                [
                    'order_state' => 'NOT_VALID',
                    "total_price" => 330.00,
                    "amount" => 330.00,
                    "currency_iso_code" => "EUR",
                    "customer_id" => "Customer_id_001",
                    "commercial_id" => "Order_42",
                    "order_id" => "Order_42-A",
                    "order_lines" => [
                        "order_line" => [
                            [
                                "offer_id" => "2016",
                                "order_line_amount" => 330.00,
                                "order_line_id" => "Order_42-a-1",
                                "order_line_quantity" => 5,
                                'order_line_state' => 'SHIPPING',
                            ]
                        ]
                    ],
                    "shop_id" => "2016"
                ],
                [
                    'order_state' => 'SHIPPING',
                    "total_price" => 330.00,
                    "amount" => 330.00,
                    "currency_iso_code" => "EUR",
                    "customer_id" => "Customer_id_001",
                    "commercial_id" => "Order_42",
                    "order_id" => "Order_42-B",
                    "order_lines" => [
                        "order_line" => [
                            [
                                "offer_id" => "2016",
                                "order_line_amount" => 330.00,
                                "order_line_id" => "Order_42-a-1",
                                "order_line_quantity" => 5,
                                'order_line_state' => 'SHIPPING',
                            ]
                        ]
                    ],
                    "shop_id" => "2016"
                ],
            ],
        ]);
    }

    private function getJsonOrderWithNegativeAmount($orderId)
    {
        return json_encode([
            'orders' => [
                [
                    'order_state' => 'SHIPPING',
                    "total_price" => 330.00,
                    "amount" => 330.00,
                    'total_commission' => 1500,
                    "currency_iso_code" => "EUR",
                    "customer_id" => "Customer_id_001",
                    "commercial_id" => $orderId,
                    "order_id" => $orderId."-A",
                    'last_updated_date' => '01-2010-30',
                    "order_lines" => [
                        [
                            "offer_id" => "2015",
                            "order_line_amount" => 173.00,
                            'shipping_taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            'taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            "order_line_id" => $orderId."-A-1",
                            "order_line_quantity" => 3,
                            'order_line_state' => 'REFUSED',
                        ],
                        [
                            "offer_id" => "2017",
                            "order_line_amount" => 330.00,
                            'shipping_taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            'taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            "order_line_id" => $orderId."-A-2",
                            "order_line_quantity" => 5,
                            'order_line_state' => 'SHIPPING',
                        ]
                    ],
                    "shop_id" => "42"
                ],
            ],
        ]);
    }

    private function getJsonOrderForNoTransfer($orderId)
    {
        return json_encode([
            'orders' => [
                [
                    'order_state' => 'WAITING_DEBIT',
                    "total_price" => 330.00,
                    "amount" => 330.00,
                    'total_commission' => 1500,
                    "currency_iso_code" => "EUR",
                    "customer_id" => "Customer_id_001",
                    "commercial_id" => $orderId,
                    "order_id" => $orderId."-A",
                    'last_updated_date' => '01-2010-30',
                    "order_lines" => [
                        [
                            "offer_id" => "2015",
                            "order_line_amount" => 173.00,
                            'shipping_taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            'taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            "order_line_id" => $orderId."-A-1",
                            "order_line_quantity" => 3,
                            'order_line_state' => 'REFUSED',
                        ],
                        [
                            "offer_id" => "2017",
                            "order_line_amount" => 330.00,
                            'shipping_taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            'taxes' => [
                                [ 'amount' => 1, 'code' => 'ECO_TAX' ],
                                [ 'amount' => 2, 'code' => 'EXP_TAX' ]
                            ],
                            "order_line_id" => $orderId."-A-2",
                            "order_line_quantity" => 5,
                            'order_line_state' => 'SHIPPING',
                        ]
                    ],
                    "shop_id" => "42"
                ],
            ],
        ]);
    }
}
