<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class PendingTotpAccessSubscriber.
 */
class PendingTotpAccessSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private array $allowedPathPrefixes = [
        '/login/totp',
        '/logout',
        '/locale',
        '/theme',
        '/_profiler',
        '/_wdt',
        '/css',
        '/js',
        '/images',
    ];

    /**
     * @brief Build pending TOTP guard subscriber.
     * @param Security $security Security helper.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @brief Restrict access while second factor is pending.
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

        $pendingUserId = (int) $request->getSession()->get('auth.totp_pending_user_id', 0);
        if ($pendingUserId <= 0) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->getId() !== $pendingUserId) {
            return;
        }

        $pathInfo = $request->getPathInfo();
        foreach ($this->allowedPathPrefixes as $prefix) {
            if (str_starts_with($pathInfo, $prefix)) {
                return;
            }
        }

        $event->setResponse(new RedirectResponse('/login/totp'));
    }

    /**
     * @brief Return subscribed events.
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
