<?php

namespace App\EventListener;

use App\Entity\StripePayout;
use App\Entity\StripeRefund;
use App\Entity\StripeTransfer;
use App\Message\PayoutFailedMessage;
use App\Message\RefundFailedMessage;
use App\Message\TransferFailedMessage;
use Doctrine\Persistence\Event\LifecycleEventArgs;
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

    public function postPersist(LifecycleEventArgs $args): void
    {
        $this->dispatchFailedOperation($args);
    }

    public function postUpdate(LifecycleEventArgs $args): void
    {
        $this->dispatchFailedOperation($args);
    }

    private function dispatchFailedOperation(LifecycleEventArgs $args): void
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
        if ($entity instanceof StripeRefund && in_array($entity->getStatus(), StripeRefund::getInvalidStatus())) {
            $message = new RefundFailedMessage($entity);
            $this->bus->dispatch($message);

            return;
        }
    }
}
