<?php

namespace App\Service;

use Stripe\Exception\ApiErrorException;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Builds log context from a throwable WITHOUT including the exception message or any
 * upstream response body. Messages and bodies coming from Stripe/Mirakl may echo
 * request data (AccountLink URLs, KYC details, personal data), so they must never be
 * written to logs or returned to callers.
 */
final class ErrorContext
{
    /**
     * @return array<string, scalar|null>
     */
    public static function fromThrowable(\Throwable $e): array
    {
        $context = ['exception' => get_class($e)];

        if ($e instanceof ApiErrorException) {
            $context['stripeErrorCode'] = $e->getStripeCode();
            $context['stripeRequestId'] = $e->getRequestId();
            $context['httpStatus'] = $e->getHttpStatus();
        } elseif ($e instanceof HttpExceptionInterface) {
            $context['httpStatus'] = $e->getResponse()->getStatusCode();
        }

        return $context;
    }
}
