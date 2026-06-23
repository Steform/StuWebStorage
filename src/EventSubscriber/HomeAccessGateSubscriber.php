<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Home\HomeAccessSessionService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @brief Redirect anonymous visitors to the antibot gate before the homepage loads.
 */
final class HomeAccessGateSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private const EXEMPT_PATHS = [
        '/home/access',
        '/home/captcha',
    ];

    /**
     * @param HomeAccessSessionService $homeAccessSessionService Session access helper.
     * @param Security $security Security helper for authenticated user bypass.
     * @param UrlGeneratorInterface $urlGenerator Route URL generator.
     */
    public function __construct(
        private readonly HomeAccessSessionService $homeAccessSessionService,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @brief Subscribe to kernel request event.
     *
     * @param void No input parameter.
     * @return array<string, array<int, int>|int>
     * @date 2026-06-23
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 6],
        ];
    }

    /**
     * @brief Enforce homepage antibot gate on / when no earlier subscriber already answered.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-06-23
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if ($event->hasResponse()) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();
        if ($pathInfo !== '/') {
            return;
        }

        foreach (self::EXEMPT_PATHS as $exempt) {
            if ($pathInfo === $exempt || str_starts_with($pathInfo, $exempt.'/')) {
                return;
            }
        }

        if ($this->security->getUser() !== null) {
            return;
        }

        if ($this->homeAccessSessionService->isBypassGranted() || $this->homeAccessSessionService->isAccessGranted()) {
            return;
        }

        $gateUrl = $this->urlGenerator->generate('storage_home_access', [
            'target' => '/',
        ]);
        $event->setResponse(new RedirectResponse($gateUrl));
    }
}
