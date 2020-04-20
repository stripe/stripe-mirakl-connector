<?php

namespace App\EventListener;

use App\Entity\StripePayout;
use App\Entity\StripeTransfer;
use App\Entity\MiraklRefund;
use App\Message\PayoutFailedMessage;
use App\Message\TransferFailedMessage;
use App\Message\RefundFailedMessage;
use Doctrine\Common\Persistence\Event\LifecycleEventArgs;
use Symfony\Component\Messenger\MessageBusInterface;

class SendFailedOperationToAlertingQueue
{
    /**
     * @var MessageBusInterface
     */
    private $bus;

    public function __construct(MessageBusInterface $bus)
    {
        $this->bus = $bus;
    }

    public function postPersist(LifecycleEventArgs $args)
    {
        $this->dispatchFailedOperation($args);
    }

    public function postUpdate(LifecycleEventArgs $args)
    {
        $this->dispatchFailedOperation($args);
    }

    private function dispatchFailedOperation(LifecycleEventArgs $args)
    {
        $entity = $args->getObject();
        if ($entity instanceof StripePayout && in_array($entity->getStatus(), StripePayout::getInvalidStatus())) {
            $message = new PayoutFailedMessage($entity);
            $this->bus->dispatch($message);

            return;
        }
        if ($entity instanceof StripeTransfer && in_array($entity->getStatus(), StripeTransfer::getInvalidStatus())) {
            $message = new TransferFailedMessage($entity);
            $this->bus->dispatch($message);

            return;
        }
        if ($entity instanceof MiraklRefund && in_array($entity->getStatus(), MiraklRefund::getInvalidStatus())) {
            $message = new RefundFailedMessage($entity);
            $this->bus->dispatch($message);

            return;
        }
    }
}
