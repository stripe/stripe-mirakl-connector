<?php

namespace App\Handler;

use App\Message\NotificationFailedMessage;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

class OperatorHttpNotificationFailedHandler implements MessageHandlerInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var MailerInterface
     */
    private $mailer;

    /**
     * @var string
     */
    private $technicalEmailFrom;

    /**
     * @var string
     */
    private $technicalEmail;

    /**
     * @var string
     */
    private $operatorNotificationUrl;

    /**
     * @var int
     */
    private $endpointDownMailNotificationThrottleDelay;

    /**
     * @var \DateTime|null
     */
    private $lastNotificationDateTime;

    public function __construct(
        MailerInterface $mailer,
        string $technicalEmailFrom,
        string $technicalEmail,
        string $operatorNotificationUrl,
        int $endpointDownMailNotificationThrottleDelay
    ) {
        $this->mailer = $mailer;
        $this->technicalEmailFrom = $technicalEmailFrom;
        $this->technicalEmail = $technicalEmail;
        $this->operatorNotificationUrl = $operatorNotificationUrl;
        $this->endpointDownMailNotificationThrottleDelay = $endpointDownMailNotificationThrottleDelay;
        $this->lastNotificationDateTime = null;
    }

    public function __invoke(NotificationFailedMessage $message)
    {
        if (!$this->shouldNotify()) {
            $this->logger->info('Endpoint unreachable, skipping mail notification to avoid flood');

            return;
        }

        $email = (new TemplatedEmail())
            ->from($this->technicalEmailFrom)
            ->to($this->technicalEmail)
            ->subject('[Stripe-Mirakl] A notification error Occurred!')
            ->htmlTemplate('emails/notifyFailed.html.twig')
            ->context([
                'operatorNotificationUrl' => $this->operatorNotificationUrl,
                'throwable' => $message->getFailedException(),
                'originalMessage' => $message->getOriginalMessage(),
            ]);

        $this->logger->info('Sending notification alert mail', [
            'technicalEmailFrom' => $this->technicalEmailFrom,
            'technicalEmail' => $this->technicalEmail,
        ]);

        $this->mailer->send($email);
    }

    private function updateLastNotificationDateTime()
    {
        $now = \DateTime::createFromFormat('U', (string) time());
        assert(false !== $now);

        $this->lastNotificationDateTime = $now;
    }

    private function shouldNotify()
    {
        if (!$this->lastNotificationDateTime) {
            $this->updateLastNotificationDateTime();

            return true;
        }

        $now = \DateTime::createFromFormat('U', (string) time());
        assert(false !== $now);
        $notifyIfAfter = $now->modify(sprintf('-%d minutes', $this->endpointDownMailNotificationThrottleDelay));

        if ($notifyIfAfter >= $this->lastNotificationDateTime) {
            $this->updateLastNotificationDateTime();

            return true;
        }

        return false;
    }
}
