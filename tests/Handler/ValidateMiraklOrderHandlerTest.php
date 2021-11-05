<?php

namespace App\Tests\Handler;

use App\Entity\MiraklProductPendingDebit;
use App\Entity\PaymentMapping;
use App\Exception\InvalidStripeAccountException;
use App\Factory\MiraklPatchShopFactory;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Handler\UpdateKYCStatusHandler;
use App\Handler\ValidateMiraklOrderHandler;
use App\Message\AccountUpdateMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Service\MiraklClient;
use App\Service\StripeClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stripe\Account;

class ValidateMiraklOrderHandlerTest extends TestCase
{
    /**
     * @var MiraklClient
     */
    private $miraklClient;


    /**
     * @var UpdateAccountLoginLinkHandler
     */
    private $handler;

    protected function setUp(): void
    {
        $this->miraklClient = $this->createMock(MiraklClient::class);

        $this->handler = new ValidateMiraklOrderHandler($this->miraklClient);
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
                    'order_id' => 'Order_66-A',
                    'customer_id' => 'Customer_id_001',
                ]),
                'Order_66-B' => new MiraklProductPendingDebit([
                    'amount' => '330',
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

        $this
            ->miraklClient
            ->expects($this->once())
            ->method('validateProductPendingDebits');

        $this->executeHandler($orders, $paymentMappings);
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
