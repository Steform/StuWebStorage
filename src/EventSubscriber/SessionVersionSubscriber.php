<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class SessionVersionSubscriber.
 */
class SessionVersionSubscriber implements EventSubscriberInterface
{
    /**
     * @brief Build session version subscriber.
     * @param Security $security Security helper.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @brief Force re-authentication when session version mismatches.
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->hasSession()) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $sessionVersion = (int) $request->getSession()->get('auth.session_version', $user->getSessionVersion());
        if ($sessionVersion !== $user->getSessionVersion()) {
            $request->getSession()->invalidate();
            $event->setResponse(new RedirectResponse('/login'));
        }
    }

    /**
     * @brief Return subscribed events map.
     * @param void No input parameter.
     * @return array<string, string>
     * @date 2026-04-23
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
        ];
    }
}
