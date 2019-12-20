<?php

namespace App\EventListener;

use App\Message\NotifiableMessageInterface;
use App\Message\NotificationFailedMessage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Debug\Exception\FlattenException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

class SendFailedMessageToAlertingQueue implements EventSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const NOTIY_OF_FAILED_WEBHOOK = 'operator_http_notification_failed';
    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    /**
     * @var bool
     */
    private $endpointDownMailNotification;

    public function __construct(MessageBusInterface $messageBus, bool $endpointDownMailNotification)
    {
        $this->messageBus = $messageBus;
        $this->endpointDownMailNotification = $endpointDownMailNotification;
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event)
    {
        if (!$this->endpointDownMailNotification || $event->willRetry()) {
            return;
        }
        $envelope = $event->getEnvelope();
        if (!is_subclass_of($envelope->getMessage(), NotifiableMessageInterface::class)) {
            // message should not trigger an alerting email
            return;
        }

        $throwable = $event->getThrowable();
        if ($throwable instanceof HandlerFailedException) {
            $throwable = $throwable->getNestedExceptions()[0];
        }
        $flattenedException = class_exists(FlattenException::class) ? FlattenException::createFromThrowable($throwable) : null;
        $this->messageBus->dispatch(new NotificationFailedMessage($throwable, $envelope->getMessage()));
    }

    public static function getSubscribedEvents()
    {
        return [
            WorkerMessageFailedEvent::class => ['onMessageFailed', -90],
        ];
    }
}
