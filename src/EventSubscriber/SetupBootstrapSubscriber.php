<?php

namespace App\EventSubscriber;

use App\Service\Setup\SetupStateService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Class SetupBootstrapSubscriber.
 */
class SetupBootstrapSubscriber implements EventSubscriberInterface
{
    /**
     * @var list<string>
     */
    private array $allowedPathPrefixes = [
        '/setup',
        '/home/access',
        '/home/captcha',
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
     * @brief Build bootstrap guard subscriber.
     * @param SetupStateService $setupStateService Setup state service.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(private readonly SetupStateService $setupStateService)
    {
    }

    /**
     * @brief Redirect to setup until first confirmed admin exists.
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-05-19
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $pathInfo = $event->getRequest()->getPathInfo();

        foreach ($this->allowedPathPrefixes as $prefix) {
            if (str_starts_with($pathInfo, $prefix)) {
                return;
            }
        }
        try {
            $setupStatus = $this->setupStateService->getSetupStatus();
        } catch (Throwable) {
            $event->setResponse(new RedirectResponse('/setup'));

            return;
        }

        if ($setupStatus === SetupStateService::STATUS_CONFIRMED_ADMIN) {
            return;
        }

        $event->setResponse(new RedirectResponse('/setup'));
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
