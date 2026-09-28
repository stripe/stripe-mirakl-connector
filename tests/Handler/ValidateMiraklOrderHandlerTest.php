<?php

namespace App\Tests\Handler;

use App\Entity\MiraklProductPendingDebit;
use App\Entity\PaymentMapping;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Handler\ValidateMiraklOrderHandler;
use App\Message\ValidateMiraklOrderMessage;
use App\Repository\PaymentMappingRepository;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Charge;
use Stripe\PaymentIntent;

class ValidateMiraklOrderHandlerTest extends TestCase
{
    /**
     * @var MiraklClient
     */
    private $miraklClient;

    private $stripeClient;

    private $paymentMappingRepository;

    private $charge;


    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        $this->miraklClient = $this->createMock(MiraklClient::class);
        $this->stripeClient = $this->createMock(StripeClient::class);
        $this->paymentMappingRepository = $this->createMock(PaymentMappingRepository::class);
        $this->charge = Charge::constructFrom([
            'id' => 'ch_valid',
            'amount' => 66000,
            'amount_refunded' => 0,
            'currency' => 'eur',
            'status' => 'succeeded',
            'captured' => true,
            'disputed' => false,
        ]);
        $this->stripeClient
            ->method('chargeRetrieve')
            ->willReturnCallback(fn () => $this->charge);

        $this->handler = new ValidateMiraklOrderHandler(
            $this->miraklClient,
            $this->stripeClient,
            $this->paymentMappingRepository
        );
        $this->handler->setLogger(new NullLogger());
    }

    private function executeHandler($orders, $paymentMappings)
    {
        ($this->handler)(new ValidateMiraklOrderMessage(
            $orders,
            $paymentMappings
        ));
    }

    public function testNominalExecute()
    {
        $orders = [
            'Order_66' => [
                'Order_66-A' => new MiraklProductPendingDebit([
                    'amount' => '330',
                    'currency_iso_code' => 'EUR',
                    'order_id' => 'Order_66-A',
                    'customer_id' => 'Customer_id_001',
                ]),
                'Order_66-B' => new MiraklProductPendingDebit([
                    'amount' => '330',
                    'currency_iso_code' => 'EUR',
                    'order_id' => 'Order_66-B',
                    'customer_id' => 'Customer_id_001',
                ]),
            ]
        ];

        $paymentMapping = new PaymentMapping();
        $paymentMapping
            ->setStripeChargeId('pi_valid')
            ->setMiraklCommercialOrderId('Order_66');

        $paymentMappings = ['Order_66' => $paymentMapping];

        $this->stripeClient
            ->expects($this->once())
            ->method('paymentIntentRetrieve')
            ->with('pi_valid')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_valid',
                'latest_charge' => 'ch_valid',
            ]));

        $this
            ->miraklClient
            ->expects($this->once())
            ->method('validateProductPendingDebits');

        $this->executeHandler($orders, $paymentMappings);
    }

    public function testRejectsUnderpaymentForUgandanShillings()
    {
        $this->charge = Charge::constructFrom([
            'id' => 'ch_ugx',
            'amount' => 5,
            'amount_refunded' => 0,
            'currency' => 'ugx',
            'status' => 'succeeded',
            'captured' => true,
            'disputed' => false,
        ]);
        $orders = [
            'Order_UGX' => [
                'Order_UGX-A' => new MiraklProductPendingDebit([
                    'amount' => '5.00',
                    'currency_iso_code' => 'UGX',
                    'order_id' => 'Order_UGX-A',
                    'customer_id' => 'Customer_id_001',
                ]),
            ],
        ];
        $paymentMapping = (new PaymentMapping())
            ->setStripeChargeId('ch_ugx')
            ->setMiraklCommercialOrderId('Order_UGX');

        $this->miraklClient
            ->expects($this->never())
            ->method('validateProductPendingDebits');

        $this->executeHandler($orders, ['Order_UGX' => $paymentMapping]);
    }

    public function testRejectsPendingCharge()
    {
        $this->charge = Charge::constructFrom([
            'id' => 'ch_pending',
            'amount' => 10000,
            'amount_refunded' => 0,
            'currency' => 'eur',
            'status' => 'pending',
            'captured' => false,
            'disputed' => false,
        ]);
        $orders = [
            'Order_PENDING' => [
                'Order_PENDING-A' => new MiraklProductPendingDebit([
                    'amount' => '100.00',
                    'currency_iso_code' => 'EUR',
                    'order_id' => 'Order_PENDING-A',
                    'customer_id' => 'Customer_id_001',
                ]),
            ],
        ];
        $paymentMapping = (new PaymentMapping())
            ->setStripeChargeId('ch_pending')
            ->setMiraklCommercialOrderId('Order_PENDING');

        $this->miraklClient
            ->expects($this->never())
            ->method('validateProductPendingDebits');

        $this->executeHandler($orders, ['Order_PENDING' => $paymentMapping]);
    }

    public function testWithNoOrders()
    {
        $paymentMapping = new PaymentMapping();
        $paymentMapping
            ->setStripeChargeId('pi_valid')
            ->setMiraklCommercialOrderId('Order_66');

        $paymentMappings = ['Order_66' => $paymentMapping];

        $this
            ->miraklClient
            ->expects($this->never())
            ->method('validateProductPendingDebits');

        $this->executeHandler([], $paymentMappings);
    }
}
