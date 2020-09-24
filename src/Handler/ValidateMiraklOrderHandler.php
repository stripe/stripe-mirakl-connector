<?php

namespace App\Handler;

use App\Message\ValidateMiraklOrderMessage;
use App\Utils\MiraklClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class ValidateMiraklOrderHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MiraklClient
     */
    private $miraklClient;


    public function __construct(
        MiraklClient $miraklClient
    ) {
        $this->miraklClient = $miraklClient;
    }

    public function __invoke(ValidateMiraklOrderMessage $message)
    {
        $ordersToValidate = $message->geOrders();

        if (empty($ordersToValidate)) {
            return;
        }

        // Prepare order for validation
        $orders = [];
        foreach ($ordersToValidate as $orderToValidate) {
            foreach ($orderToValidate as $order) {
                $orders[] = [
                    'amount' => $order['amount'],
                    'order_id' => $order['order_id'],
                    'customer_id' => $order['customer_id'],
                    'payment_status' => 'OK',
                ];
            }
        }

        $nbOrders = count($orders);
        $this->logger->info("Validate {$nbOrders} Mirakl order(s)");
        $this->miraklClient->validatePayments($orders);
    }
}
