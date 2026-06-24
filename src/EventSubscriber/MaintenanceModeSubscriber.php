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
 * @brief Replace the public homepage with a maintenance page when maintenance mode is active.
 */
final class MaintenanceModeSubscriber implements EventSubscriberInterface
{
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
     * @brief Serve maintenance page on homepage when maintenance mode is active.
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

        if (!$this->siteAccessGateService->isMaintenanceModeEnabled()) {
            return;
        }

        if ($this->authorizationChecker->isGranted('ROLE_ADMIN')) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();
        if ($pathInfo !== '/') {
            return;
        }

        $response = new Response(
            $this->twig->render('maintenance/index.html.twig', [
                'maintenanceMessage' => $this->siteAccessGateService->getMaintenanceMessage(),
            ]),
            Response::HTTP_SERVICE_UNAVAILABLE,
            [
                'Retry-After' => '3600',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
            ],
        );

        $event->setResponse($response);
    }
}
