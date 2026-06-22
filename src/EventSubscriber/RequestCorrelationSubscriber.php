<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class RequestCorrelationSubscriber.
 */
class RequestCorrelationSubscriber implements EventSubscriberInterface
{
    private const ATTRIBUTE_REQUEST_ID = 'request_id';
    private const HEADER_REQUEST_ID = 'X-Request-Id';

    /**
     * @brief Attach correlation identifier on request lifecycle.
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $requestId = trim((string) $request->headers->get(self::HEADER_REQUEST_ID, ''));
        if ($requestId === '') {
            $requestId = bin2hex(random_bytes(16));
        }

        $request->attributes->set(self::ATTRIBUTE_REQUEST_ID, $requestId);
    }

    /**
     * @brief Propagate request identifier to response header.
     * @param ResponseEvent $event Kernel response event.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $requestId = (string) $event->getRequest()->attributes->get(self::ATTRIBUTE_REQUEST_ID, '');
        if ($requestId === '') {
            return;
        }

        $event->getResponse()->headers->set(self::HEADER_REQUEST_ID, $requestId);
    }

    /**
     * @brief Return subscribed events.
     * @param void No input parameter.
     * @return array<string, string>
     * @date 2026-05-06
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }
}
