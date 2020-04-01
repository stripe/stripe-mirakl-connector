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
            switch ($url) {
                case 'https://mirakl.net/api/orders?customer_debited=true&start_update_date=2019-01-01T00%3A00%3A00%2B0000':
                    return new MockResponse($this->getJsonMiraklOrders());
                case 'https://mirakl.net/api/orders?customer_debited=true&order_ids=order_5%2Corder_6':
                    return new MockResponse($this->getJsonSpecifiedMiraklOrders());
                case 'https://mirakl.net/api/orders?customer_debited=true&order_ids=order_1':
                    return new MockResponse($this->getJsonAlreadyExistingMiraklOrders());
                case 'https://mirakl.net/api/orders?customer_debited=true&order_ids=order_8':
                    return new MockResponse($this->getJsonInvalidAmountMiraklOrders());
                case 'https://mirakl.net/api/shops':
                    return new MockResponse($this->getReturnJsonShops());
                case 'https://mirakl.net/api/shops?paginate=true&shop_ids=1':
                case 'https://mirakl.net/api/shops?paginate=true&shop_ids=11':
                case 'https://mirakl.net/api/shops?paginate=true&shop_ids=13':
                case 'https://mirakl.net/api/shops?paginate=true&shop_ids=123':
                case 'https://mirakl.net/api/shops?paginate=true&shop_ids=2000':
                    return new MockResponse($this->getJsonShop());
                case 'https://mirakl.net/api/shops?paginate=false':
                case 'https://mirakl.net/api/shops?paginate=false&updated_since=2019-10-01T00%3A00%3A00%2B0000':
                    return new MockResponse($this->getJsonShops());
                case 'https://mirakl.net/api/invoices?start_date=2019-01-01T00%3A00%3A00%2B0000':
                    return new MockResponse($this->getJsonMiraklInvoices());
                case 'https://mirakl.net/api/invoices?shop=1':
                    return new MockResponse($this->getSpecifiedJsonMiraklInvoices());
                case 'https://mirakl.net/api/payment/refund':
                    return new MockResponse($this->getPendingRefunds());
                default:
                    return new MockResponse($this->getEmptyJson());
            }
        };

        parent::__construct($responseFactory, 'https://mirakl.net');
    }

    private function getMiraklOrder($orderId)
    {
        return [
            'id' => 1,
            'order_id' => $orderId,
            'shop_id' => '1',
            'total_price' => '24',
            'total_commission' => '5',
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

    private function getMiraklInvoice($invoiceId)
    {
        return [
            'invoice_id' => $invoiceId,
            'shop_id' => 1,
            'summary' => [
                'amount_transferred' => 5000,
                'total_subscription_incl_tax' => 1000,
                'total_other_credits_incl_tax' => 1500,
                'total_other_invoices_incl_tax' => 2000
            ],
            'currency_iso_code' => 'eur',
            'end_time' => '2019-09-24T14:00:40Z'
        ];
    }

    private function getJsonMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(4)
            ]
        ]);
    }

    private function getSpecifiedJsonMiraklInvoices()
    {
        return json_encode([
            'invoices' => [
                $this->getMiraklInvoice(5)
            ]
        ]);
    }

    private function getPendingRefunds() 
    {
        return json_encode([
            "orders"=> [
              "order"=> [
                [
                  "amount"=> 100,
                  "currency_iso_code"=> "USD",
                  "customer_id"=> "Customer_id_001",
                  "order_commercial_id"=> "Order_Amount_Breakdown_0002",
                  "order_id"=> "Order_Amount_Breakdown_0002-A",
                  "order_lines"=> [
                    "order_line"=> [
                      [
                        "offer_id"=> "2130",
                        "order_line_amount"=> 20,
                        "order_line_id"=> "Order_Amount_Breakdown_0002-A-1",
                        "order_line_quantity"=> 10,
                        "refunds"=> [
                          "refund"=> [
                            [
                              "amount"=> 10,
                              "id"=> "1100"
                            ],
                            [
                              "amount"=> 5,
                              "id"=> "1103"
                            ]
                          ]
                        ]
                      ],
                      [
                        "offer_id"=> "2130",
                        "order_line_amount"=> 80,
                        "order_line_id"=> "Order_Amount_Breakdown_0002-A-2",
                        "order_line_quantity"=> 10,
                        "refunds"=> [
                          "refund"=> [
                            [
                              "amount"=> 8,
                              "id"=> "1101"
                            ]
                          ]
                        ]
                      ]
                    ]
                  ],
                  "payment_workflow"=> "PAY_ON_ACCEPTANCE",
                  "shop_id"=> "2000"
                ],
                [
                  "amount"=> 100,
                  "currency_iso_code"=> "EUR",
                  "customer_id"=> "Customer_id_001",
                  "order_commercial_id"=> "Order_Amount_Breakdown_0003",
                  "order_id"=> "Order_Amount_Breakdown_0002-A",
                  "order_lines"=> [
                    "order_line"=> [
                      [
                        "offer_id"=> "1599",
                        "order_line_amount"=> 100,
                        "order_line_id"=> "Order_Amount_Breakdown_0003-A-1",
                        "order_line_quantity"=> 1,
                        "refunds"=> [
                          "refund"=> [
                            [
                              "amount"=> 10,
                              "id"=> "1199"
                            ]
                          ]
                        ]
                      ]
                    ]
                  ],
                  "payment_workflow"=> "PAY_ON_ACCEPTANCE",
                  "shop_id"=> "2002"
                ]
              ]
            ],
            "total_count"=> 2
          ]);
    }
}
