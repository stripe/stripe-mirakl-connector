<?php

namespace App\Handler;

use App\Message\AccountUpdateMessage;
use App\Message\NotifiableMessageInterface;
use App\Message\PayoutFailedMessage;
use App\Message\TransferFailedMessage;
use App\Message\RefundFailedMessage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\Messenger\Handler\MessageSubscriberInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OperatorHttpNotificationHandler implements MessageSubscriberInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var HttpClientInterface
     */
    private $client;

    /**
     * @var string
     */
    private $operatorNotificationUrl;

    public function __construct(HttpClientInterface $client, string $operatorNotificationUrl)
    {
        $this->client = $client;
        $this->operatorNotificationUrl = $operatorNotificationUrl;
    }

    public function __invoke(NotifiableMessageInterface $message)
    {
        if (empty($this->operatorNotificationUrl)) {
            return;
        }

        $response = $this->client->request('POST', $this->operatorNotificationUrl, [
            'json' => $message->getContent(),
        ]);

        $responseCode = $response->getStatusCode();

        if ($responseCode >= 400 && $responseCode < 600) {
            $this->logger->error('Error while notifying the operator', ['code' => $responseCode]);
        }

        // Will throw Symfony\Component\HttpClient\Exception\ClientException on error
        $content = $response->getContent();

        $this->logger->info('Notification sent to operator', [
            'code' => $responseCode,
            'response' => $content,
        ]);
    }

    public static function getHandledMessages(): iterable
    {
        yield TransferFailedMessage::class;
        yield RefundFailedMessage::class;
        yield PayoutFailedMessage::class;
        yield AccountUpdateMessage::class => [
            'from_transport' => 'operator_http_notification',
        ];
    }
}
