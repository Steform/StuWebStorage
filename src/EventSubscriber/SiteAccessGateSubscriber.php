<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Site\SiteAccessGateService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * @brief Redirect visitors to the antibot gate when enabled.
 */
final class SiteAccessGateSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private array $exemptPathPrefixes = [
        '/access-gate',
        '/setup',
        '/login',
        '/logout',
        '/locale',
        '/theme',
        '/invite/activate',
        '/p/',
        '/download/public',
        '/css',
        '/js',
        '/images',
        '/_profiler',
        '/_wdt',
    ];

    /**
     * @param SiteAccessGateService $siteAccessGateService Gate settings and session service.
     * @param AuthorizationCheckerInterface $authorizationChecker Security authorization helper.
     * @param UrlGeneratorInterface $urlGenerator URL generator.
     */
    public function __construct(
        private readonly SiteAccessGateService $siteAccessGateService,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @brief Subscribe to kernel request event.
     *
     * @param void No input parameter.
     * @return array<string, array<int, int>|int>
     * @date 2026-06-22
     * @author Stephane H.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 7],
        ];
    }

    /**
     * @brief Enforce site access gate before controller execution.
     *
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->siteAccessGateService->isGateEnabled()) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();
        foreach ($this->exemptPathPrefixes as $prefix) {
            if ($pathInfo === $prefix || str_starts_with($pathInfo, $prefix)) {
                return;
            }
        }

        $adminBypass = $this->authorizationChecker->isGranted('ROLE_ADMIN');
        if ($this->siteAccessGateService->isBypassGranted($adminBypass)) {
            return;
        }

        $gateUrl = $this->urlGenerator->generate('storage_access_gate', [
            'target' => $pathInfo,
        ]);
        $event->setResponse(new RedirectResponse($gateUrl));
    }
}
