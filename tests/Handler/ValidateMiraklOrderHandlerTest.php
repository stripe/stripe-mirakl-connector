<?php

namespace App\Tests\Handler;

use App\Entity\StripeCharge;
use App\Exception\InvalidStripeAccountException;
use App\Factory\MiraklPatchShopFactory;
use App\Handler\UpdateAccountLoginLinkHandler;
use App\Handler\UpdateKYCStatusHandler;
use App\Handler\ValidateMiraklOrderHandler;
use App\Message\AccountUpdateMessage;
use App\Message\ValidateMiraklOrderMessage;
use App\Utils\MiraklClient;
use App\Utils\StripeProxy;
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

        $logger = new NullLogger();

        $this->handler->setLogger($logger);
    }


    public function testNominalExecute()
    {

        $orders = [
            'Order_66' => [
                'Order_66-A' => [
                    'amount' => '330',
                    'order_id' => 'Order_66-A',
                    'customer_id' => 'Customer_id_001',
                ],
                'Order_66-B' => [
                    'amount' => '330',
                    'order_id' => 'Order_66-B',
                    'customer_id' => 'Customer_id_001',
                ],
            ]
        ];

        $stripePayment = new StripeCharge();
        $stripePayment
            ->setStripeChargeId('pi_valid')
            ->setMiraklOrderId('Order_66');

        $stripePayments = ['Order_66' => $stripePayment];

        $this
            ->miraklClient
            ->expects($this->once())
             ->method('validatePayments');

        $message = new ValidateMiraklOrderMessage($orders, $stripePayments);

        $handler = $this->handler;
        $handler($message);
    }

    public function testWithNoOrders()
    {
        $stripePayment = new StripeCharge();
        $stripePayment
            ->setStripeChargeId('pi_valid')
            ->setMiraklOrderId('Order_66');

        $stripePayments = ['Order_66' => $stripePayment];

        $this
            ->miraklClient
            ->expects($this->never())
            ->method('validatePayments');

        $message = new ValidateMiraklOrderMessage([], $stripePayments);

        $handler = $this->handler;
        $handler($message);
    }

}
