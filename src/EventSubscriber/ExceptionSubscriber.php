<?php


namespace App\EventSubscriber;

use App\Helper\LoggerHelper;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ExceptionSubscriber implements EventSubscriberInterface
{
    private $logger;

    public function __construct(LoggerHelper $logger)
    {
        $this->logger = $logger;
    }

    public static function getSubscribedEvents()
    {
        // return the subscribed events, their methods and priorities
        return [
            KernelEvents::EXCEPTION => [
                ['logException', 0],
            ],
        ];
    }

    public function logException(ExceptionEvent $event)
    {
        $exception = $event->getThrowable();
        $this->logger->getLogger()->error($exception->getMessage(),[]);
    }

}