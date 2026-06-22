<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class ThemeSubscriber.
 */
class ThemeSubscriber implements EventSubscriberInterface
{
    /**
     * @brief Build theme subscriber.
     * @param string $defaultTheme Default theme.
     * @param string $fallbackTheme Fallback theme.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly string $defaultTheme = 'light',
        private readonly string $fallbackTheme = 'light'
    ) {
    }

    /**
     * @brief Resolve and expose current theme on request.
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
        $resolvedTheme = null;

        $queryTheme = $this->normalizeTheme((string) $request->query->get('theme', ''));
        if ($queryTheme !== null) {
            $resolvedTheme = $queryTheme;
        }

        if ($resolvedTheme === null && $request->hasSession()) {
            $sessionTheme = $this->normalizeTheme((string) $request->getSession()->get('_theme', ''));
            if ($sessionTheme !== null) {
                $resolvedTheme = $sessionTheme;
            }
        }

        if ($resolvedTheme === null) {
            $cookieTheme = $this->normalizeTheme((string) $request->cookies->get('site_theme', ''));
            if ($cookieTheme !== null) {
                $resolvedTheme = $cookieTheme;
            }
        }

        if ($resolvedTheme === null) {
            $resolvedTheme = $this->normalizeTheme($this->defaultTheme);
        }

        if ($resolvedTheme === null) {
            $resolvedTheme = $this->normalizeTheme($this->fallbackTheme) ?? 'light';
        }

        if ($request->hasSession()) {
            $request->getSession()->set('_theme', $resolvedTheme);
        }

        $request->attributes->set('app_theme', $resolvedTheme);
    }

    /**
     * @brief Normalize and validate theme value.
     * @param string $theme Raw theme value.
     * @return string|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    private function normalizeTheme(string $theme): ?string
    {
        $normalized = strtolower(trim($theme));
        if (\in_array($normalized, ['light', 'dark'], true)) {
            return $normalized;
        }

        return null;
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
