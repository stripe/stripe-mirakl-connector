<?php

namespace App\Tests\Monolog\Processor;

use App\Monolog\Processor\SensitiveDataProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;

class SensitiveDataProcessorTest extends TestCase
{
    private function record(string $message, array $context = []): LogRecord
    {
        return new LogRecord(new \DateTimeImmutable(), 'test', Level::Error, $message, $context);
    }

    public function testRedactsSensitiveContextKeys(): void
    {
        $processed = (new SensitiveDataProcessor())($this->record('msg', [
            'requirements' => ['currently_due' => ['individual.id_number']],
            'url' => 'https://connect.stripe.com/setup/s/abc',
            'nested' => ['Token' => 'secret-token', 'shopId' => 12],
            'shopId' => 42,
        ]));

        $this->assertSame('[redacted]', $processed->context['requirements']);
        $this->assertSame('[redacted]', $processed->context['url']);
        $this->assertSame('[redacted]', $processed->context['nested']['Token']);
        $this->assertSame(12, $processed->context['nested']['shopId']);
        $this->assertSame(42, $processed->context['shopId']);
    }

    public function testMasksSensitivePatternsInStrings(): void
    {
        $processed = (new SensitiveDataProcessor())($this->record(
            'Could not add https://connect.stripe.com/setup/s/mov7fZc0o4Yx to shop, see /refresh?token=abcdef123 using sk_live_abc123',
            ['error' => 'Bad value https://dashboard.stripe.com/express/xyz']
        ));

        $this->assertStringNotContainsString('connect.stripe.com', $processed->message);
        $this->assertStringNotContainsString('abcdef123', $processed->message);
        $this->assertStringNotContainsString('sk_live_abc123', $processed->message);
        $this->assertStringContainsString('token=[redacted]', $processed->message);
        $this->assertStringNotContainsString('dashboard.stripe.com', $processed->context['error']);
    }

    public function testUpstreamExceptionsAreReducedToSafeContext(): void
    {
        $stripeError = InvalidRequestException::factory('Invalid https://connect.stripe.com/setup/s/abc for acct_1', 400);

        $processed = (new SensitiveDataProcessor())($this->record('msg', ['exception' => $stripeError]));

        $this->assertSame(InvalidRequestException::class, $processed->context['exception']['exception']);
        $this->assertSame(400, $processed->context['exception']['httpStatus']);
        $this->assertStringNotContainsString('connect.stripe.com', json_encode($processed->context));
    }

    public function testWrappedUpstreamExceptionAndDuplicatedErrorMessageAreRedacted(): void
    {
        $stripeError = InvalidRequestException::factory('No such account: acct_secret_detail', 404);
        $wrapped = new HandlerFailedException(new Envelope(new \stdClass()), [$stripeError]);

        // Mimics what Symfony Messenger logs when a handler fails.
        $processed = (new SensitiveDataProcessor())($this->record(
            'Error thrown while handling message. Error: "No such account: acct_secret_detail"',
            ['exception' => $wrapped, 'error' => $wrapped->getMessage()]
        ));

        $this->assertStringNotContainsString('acct_secret_detail', $processed->message);
        $this->assertSame('[redacted]', $processed->context['error']);
        $this->assertSame(InvalidRequestException::class, $processed->context['exception']['exception']);
        $this->assertStringNotContainsString('acct_secret_detail', json_encode($processed->context));
    }

    public function testNonUpstreamExceptionsAreKept(): void
    {
        $exception = new \RuntimeException('boom');

        $processed = (new SensitiveDataProcessor())($this->record('msg', ['exception' => $exception]));

        $this->assertSame($exception, $processed->context['exception']);
    }
}
