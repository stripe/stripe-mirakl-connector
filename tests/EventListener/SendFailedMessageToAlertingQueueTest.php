<?php

namespace App\Tests\EventListener;

use App\EventListener\SendFailedMessageToAlertingQueue;
use App\Message\AccountUpdateMessage;
use App\Tests\DummyMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

class SendFailedMessageToAlertingQueueTest extends TestCase
{
    private $listener;
    private $messageBus;

    protected function setUp(): void
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $this->listener = new SendFailedMessageToAlertingQueue($this->messageBus, true);
    }

    /**
     * @dataProvider skippableMessageProvider
     */
    public function testSkippableMessages($retry, $endpointDownMailNotification)
    {
        $this->listener = new SendFailedMessageToAlertingQueue($this->messageBus, $endpointDownMailNotification);

        $envelope = new Envelope(new DummyMessage('Message'), []);
        $event = new WorkerMessageFailedEvent($envelope, 'receiver', new \Exception(), $retry);

        $this->messageBus
            ->expects($this->never())
            ->method('dispatch');

        $this->listener->onMessageFailed($event);
    }

    public function skippableMessageProvider()
    {
        return [
            'retryable message' => [true, true],
            'endpoint notification disabled' => [false, false],
            'bad class message' => [false, true],
        ];
    }

    public function testMessageDispatch()
    {
        $this->messageBus = $this->createMock(MessageBusInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $this->listener = new SendFailedMessageToAlertingQueue($this->messageBus, true);
        $this->listener->setLogger($logger);

        $envelope = new Envelope(new AccountUpdateMessage(12, 'acct_123'), []);
        $exception = new HandlerFailedException($envelope, [new \Exception('throwable message')]);
        $event = new WorkerMessageFailedEvent($envelope, 'receiver', $exception, false);
        $this->messageBus
            ->expects($this->once())
            ->method('dispatch')
            ->will($this->returnValue($envelope));

        $this->listener->onMessageFailed($event);
    }

    public function testGetSubscribedEvents()
    {
        $this->assertEquals([
            WorkerMessageFailedEvent::class => ['onMessageFailed', -90],
        ], SendFailedMessageToAlertingQueue::getSubscribedEvents());
    }
}
