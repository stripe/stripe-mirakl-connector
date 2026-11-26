<?php

namespace App\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\PermissionException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

class ExceptionSubscriber implements EventSubscriberInterface
{
    private const PUBLIC_PATH_PREFIX = '/api/public/';

    public function onKernelException(ExceptionEvent $event): void
    {
        $e = $event->getThrowable();
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

        if ($event->hasResponse()) {
            return;
        }

        // Fallbacks: upstream (Stripe/Mirakl) exception messages and bodies can contain
        // sensitive data, so they are never rendered, whatever the debug mode is.
        if ($e instanceof ApiErrorException || $e instanceof HttpClientExceptionInterface) {
            $event->setResponse(new Response('Upstream service error.', Response::HTTP_BAD_GATEWAY));

            return;
        }

        // Public endpoints (Stripe webhooks, onboarding refresh) must never expose exception details.
        if (str_starts_with($event->getRequest()->getPathInfo(), self::PUBLIC_PATH_PREFIX)
            && !$e instanceof HttpExceptionInterface
        ) {
            $event->setResponse(new Response('Internal server error.', Response::HTTP_INTERNAL_SERVER_ERROR));
        }
    }

    /**
     * @return string[]
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }
}
