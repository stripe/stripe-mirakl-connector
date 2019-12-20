<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Stripe\Exception\PermissionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;

class ExceptionSubscriber implements EventSubscriberInterface
{
    public function onKernelException(GetResponseForExceptionEvent $event)
    {
        $e = $event->getException();
        if ($e instanceof NotEncodableValueException) {
            $response = new Response('Invalid JSON format', Response::HTTP_BAD_REQUEST);
            $event->setResponse($response);
        }

        if ($e instanceof PermissionException) {
            $response = new Response('Cannot find the Stripe account corresponding to this stripe Id', Response::HTTP_BAD_REQUEST);
            $event->setResponse($response);
        }

        if ($e instanceof UniqueConstraintViolationException) {
            $response = new Response('The provided Mirakl Shop ID or Stripe User Id is already mapped', Response::HTTP_CONFLICT);
            $event->setResponse($response);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }
}
