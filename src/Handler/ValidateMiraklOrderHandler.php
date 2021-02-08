<?php

namespace App\Handler;

use App\Message\ValidateMiraklOrderMessage;
use App\Service\MiraklClient;
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
        $ordersByCommercialId = $message->getOrders();

        if (empty($ordersByCommercialId)) {
            return;
        }

        // Prepare order for validation
        $orders = [];
        $paymentMappings = $message->getPaymentMappings();
        foreach ($ordersByCommercialId as $commercialId => $ordersById) {
            foreach ($ordersById as $order) {
                $orders[] = [
                    'amount' => $order->getAmountDue(),
                    'customer_id' => $order->getCustomerId(),
                    'order_id' => $order->getOrderId(),
                    'payment_status' => 'OK',
                    'transaction_number' => $paymentMappings[$commercialId]->getStripeChargeId()
                ];
            }
        }

        $this->logger->info('Validate ' . count($orders) . ' Mirakl order(s)');
        $this->miraklClient->validateProductPendingDebits($orders);
    }
}
