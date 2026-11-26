<?php

declare(strict_types=1);

namespace App\Monolog\Processor;

use App\Service\ErrorContext;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;

/**
 * Last line of defense: scrubs secrets and personal data that could still reach the logs
 * (and therefore the critical-error emails), e.g. through exceptions logged by the framework.
 */
class SensitiveDataProcessor implements ProcessorInterface
{
    private const REDACTED = '[redacted]';

    /**
     * Context keys (lowercase) whose value is never logged.
     */
    private const SENSITIVE_KEYS = [
        'requirements',
        'url',
        'account_link',
        'accountlink',
        'login_link',
        'loginlink',
        'token',
        'onboardingtoken',
        'onboarding_token',
        'authorization',
        'stripe-signature',
        'signature',
        'secret',
        'api_key',
        'apikey',
        'password',
    ];

    /**
     * Patterns replaced in any logged string.
     */
    private const SENSITIVE_PATTERNS = [
        // Stripe AccountLink / LoginLink / dashboard URLs
        '~https://(?:connect|dashboard)\.stripe\.com/[^\s"\'<>]*~i' => '[redacted-stripe-url]',
        // Onboarding refresh token carried in the query string
        '~([?&]token=)[^&\s"\'<>]+~i' => '$1[redacted]',
        // Stripe secret / restricted API keys and webhook secrets
        '~\b(?:sk|rk|whsec)_[A-Za-z0-9_]+~' => '[redacted-secret]',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $record->message;
        $context = $record->context;

        // Frameworks (Messenger, HttpKernel) log failures with the raw exception, wrapped or not,
        // and often duplicate its message in an `error` field or in the interpolated message.
        $upstream = $this->findUpstreamException($context['exception'] ?? null);
        if (null !== $upstream) {
            $rawMessage = $upstream->getMessage();
            if ('' !== $rawMessage) {
                $message = str_replace($rawMessage, self::REDACTED, $message);
            }
            if (isset($context['error']) && is_string($context['error'])) {
                $context['error'] = self::REDACTED;
            }
            $context['exception'] = ErrorContext::fromThrowable($upstream);
        }

        return $record->with(
            message: $this->scrubString($message),
            context: $this->scrubArray($context),
            extra: $this->scrubArray($record->extra),
        );
    }

    /**
     * Looks through previous and Messenger-wrapped exceptions for a Stripe/HTTP client one.
     */
    private function findUpstreamException(mixed $value): ?\Throwable
    {
        if (!$value instanceof \Throwable) {
            return null;
        }

        if ($value instanceof ApiErrorException || $value instanceof HttpClientExceptionInterface) {
            return $value;
        }

        if ($value instanceof HandlerFailedException) {
            foreach ($value->getWrappedExceptions() as $wrapped) {
                $found = $this->findUpstreamException($wrapped);
                if (null !== $found) {
                    return $found;
                }
            }
        }

        return $this->findUpstreamException($value->getPrevious());
    }

    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
                $data[$key] = self::REDACTED;
            } else {
                $data[$key] = $this->scrubValue($value);
            }
        }

        return $data;
    }

    private function scrubValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->scrubString($value);
        }

        if (is_array($value)) {
            return $this->scrubArray($value);
        }

        // Messages of upstream (Stripe, Mirakl) exceptions may echo request data.
        if ($value instanceof ApiErrorException || $value instanceof HttpClientExceptionInterface) {
            return ErrorContext::fromThrowable($value);
        }

        return $value;
    }

    private function scrubString(string $value): string
    {
        return preg_replace(array_keys(self::SENSITIVE_PATTERNS), array_values(self::SENSITIVE_PATTERNS), $value) ?? $value;
    }
}
