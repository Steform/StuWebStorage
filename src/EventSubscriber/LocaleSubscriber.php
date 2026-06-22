<?php

namespace App\EventSubscriber;

use App\Service\Locale\LocaleConfigurationService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Class LocaleSubscriber.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    /**
     * @brief Build locale subscriber with configurable strategy.
     * @param LocaleConfigurationService $localeConfigurationService Locale configuration service.
     * @param array<int, string> $supportedLocales Supported locales.
     * @param string $defaultLocale Primary technical fallback locale.
     * @param string $fallbackLocale Ultimate fallback locale.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function __construct(
        private readonly LocaleConfigurationService $localeConfigurationService,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
        private readonly string $defaultLocale = 'en',
        private readonly string $fallbackLocale = 'fr'
    ) {
    }

    /**
     * @brief Resolve locale for each main request.
     * @param RequestEvent $event Kernel request event.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $configuration = $this->localeConfigurationService->getConfiguration();
        $activeLocales = is_array($configuration['activeLocales'] ?? null) ? $configuration['activeLocales'] : $this->supportedLocales;
        $configuredDefaultLocale = is_string($configuration['defaultLocale'] ?? null) ? $configuration['defaultLocale'] : $this->defaultLocale;
        if ($activeLocales === []) {
            $activeLocales = $this->supportedLocales;
        }

        $request->attributes->set('active_locales', $activeLocales);
        $request->attributes->set('default_locale', $configuredDefaultLocale);

        $localeFromQuery = $this->normalizeLocale((string) $request->query->get('lang', ''), $activeLocales);
        if ($localeFromQuery !== null) {
            $request->setLocale($localeFromQuery);
            if ($request->hasSession()) {
                $request->getSession()->set('_locale', $localeFromQuery);
            }

            return;
        }

        if ($request->hasSession()) {
            $localeFromSession = $this->normalizeLocale((string) $request->getSession()->get('_locale', ''), $activeLocales);
            if ($localeFromSession !== null) {
                $request->setLocale($localeFromSession);

                return;
            }
        }

        $localeFromCookie = $this->normalizeLocale((string) $request->cookies->get('site_locale', ''), $activeLocales);
        if ($localeFromCookie !== null) {
            $request->setLocale($localeFromCookie);

            return;
        }

        foreach ($request->getLanguages() as $browserLocale) {
            $normalizedBrowserLocale = $this->normalizeLocale($browserLocale, $activeLocales);
            if ($normalizedBrowserLocale !== null) {
                $request->setLocale($normalizedBrowserLocale);
                if ($request->hasSession()) {
                    $request->getSession()->set('_locale', $normalizedBrowserLocale);
                }

                return;
            }
        }

        $defaultLocale = $this->normalizeLocale($configuredDefaultLocale, $activeLocales)
            ?? $this->normalizeLocale($this->defaultLocale, $activeLocales);
        if ($defaultLocale !== null) {
            $request->setLocale($defaultLocale);

            return;
        }

        $fallbackLocale = $this->normalizeLocale($this->fallbackLocale, $activeLocales) ?? ($activeLocales[0] ?? 'fr');
        $request->setLocale($fallbackLocale);
    }

    /**
     * @brief Normalize locale to 2-char supported format.
     * @param string $locale Raw locale value.
     * @param array<int, string> $allowedLocales Allowed locales.
     * @return string|null
     * @date 2026-05-08
     * @author Stephane H.
     */
    private function normalizeLocale(string $locale, array $allowedLocales): ?string
    {
        $normalized = strtolower(trim($locale));
        if ($normalized === '') {
            return null;
        }

        $normalized = substr(str_replace('_', '-', $normalized), 0, 2);
        if (\in_array($normalized, ['nb', 'nn'], true)) {
            $normalized = 'no';
        }

        if (\in_array($normalized, $allowedLocales, true)) {
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
