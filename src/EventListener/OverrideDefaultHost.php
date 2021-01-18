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

    public function onKernelRequest(RequestEvent $event)
    {
        if ($event->isMasterRequest() && '' !== $this->baseHostOverride) {
            $this->router->getContext()->setHost($this->baseHostOverride);
        }
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => [
                ['onKernelRequest', 0],
            ],
        ];
    }
}
