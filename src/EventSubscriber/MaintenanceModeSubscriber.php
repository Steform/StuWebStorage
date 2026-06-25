<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Site\SiteAccessGateService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;

/**
 * @brief Block the platform for non-admin visitors while maintenance mode is active.
 */
final class MaintenanceModeSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private const EXEMPT_PATH_PREFIXES = [
        '/login',
        '/logout',
        '/setup',
        '/locale',
        '/theme',
        '/css',
        '/js',
        '/images',
        '/_profiler',
        '/_wdt',
    ];

    /**
     * @param SiteAccessGateService $siteAccessGateService Platform settings service.
     * @param AuthorizationCheckerInterface $authorizationChecker Security authorization helper.
     * @param Environment $twig Twig environment.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly Environment $twig,
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
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    /**
     * @brief Serve maintenance page on all routes except login and static assets for non-admins.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->siteAccessGateService->isMaintenanceModeEnabled()) {
            return;
        }

        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();
        if ($this->isExemptPath($pathInfo)) {
            return;
        }

        $event->setResponse($this->buildMaintenanceResponse());
    }

    /**
     * @brief Check whether a request path stays reachable during maintenance for non-admins.
     *
     * @param string $pathInfo Request path info.
     * @return bool True when the route must remain accessible.
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function isExemptPath(string $pathInfo): bool
    {
        foreach (self::EXEMPT_PATH_PREFIXES as $prefix) {
            if ($pathInfo === $prefix || str_starts_with($pathInfo, $prefix.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @brief Build HTTP 503 maintenance page response.
     *
     * @param void No input parameter.
     * @return Response Maintenance page with cache-busting headers.
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildMaintenanceResponse(): Response
    {
        return new Response(
            $this->twig->render('maintenance/index.html.twig', [
                'maintenanceMessage' => $this->siteAccessGateService->getMaintenanceMessage(),
            ]),
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Retry-After' => '3600',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );
    }
}
