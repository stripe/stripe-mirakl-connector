<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;

class OverrideDefaultHost implements EventSubscriberInterface
{
    /**
     * @var RouterInterface
     */
    private $router;

    /**
     * @var string
     */
    private $baseHostOverride;

    public function __construct(RouterInterface $router, string $baseHostOverride)
    {
        $this->router = $router;
        $this->baseHostOverride = $baseHostOverride;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest() && '' !== $this->baseHostOverride) {
            $context = $this->router->getContext();
            $host = parse_url($context->getScheme().'://'.$this->baseHostOverride);
            if (false === $host || !isset($host['host'])) {
                throw new \InvalidArgumentException('BASE_HOST must be a hostname with an optional port.');
            }

            $context->setHost($host['host']);
            if (isset($host['port'])) {
                if ('https' === $context->getScheme()) {
                    $context->setHttpsPort($host['port']);
                } else {
                    $context->setHttpPort($host['port']);
                }
            }
        }
    }

    /**
     * @return array[]
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequest', 0],
            ],
        ];
    }
}
