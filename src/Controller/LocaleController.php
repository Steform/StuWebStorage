<?php

namespace App\Controller;

use App\Service\Locale\LocaleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller LocaleController.
 */
class LocaleController extends AbstractController
{
    /**
     * @brief Build locale controller.
     * @param LocaleConfigurationService $localeConfigurationService Locale configuration service.
     * @param array<int, string> $supportedLocales Supported locales.
     * @return void
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function __construct(
        private readonly LocaleConfigurationService $localeConfigurationService,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no']
    ) {
    }

    /**
     * @brief Persist selected locale and redirect back.
     * @param string $locale Locale candidate.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-05-08
     * @author Stephane H.
     */
    #[Route('/locale/{locale}', name: 'locale_switch', methods: ['GET'])]
    public function switch(string $locale, Request $request): Response
    {
        $configuration = $this->localeConfigurationService->getConfiguration();
        $activeLocales = is_array($configuration['activeLocales'] ?? null) ? $configuration['activeLocales'] : $this->supportedLocales;
        $fallbackLocale = is_string($configuration['defaultLocale'] ?? null) ? $configuration['defaultLocale'] : 'en';
        $normalizedLocale = $this->normalizeLocale($locale, $activeLocales);
        if ($normalizedLocale === null) {
            $this->addFlash('warning', 'locale.invalid');
            $normalizedLocale = $this->normalizeLocale($fallbackLocale, $activeLocales) ?? ($activeLocales[0] ?? 'en');
        }

        if ($request->hasSession()) {
            $request->getSession()->set('_locale', $normalizedLocale);
        }

        $referer = (string) $request->headers->get('referer', '');
        $targetUrl = $referer !== '' ? $referer : $this->generateUrl('app_home');

        $response = new RedirectResponse($targetUrl);
        $response->headers->setCookie(
            Cookie::create('site_locale')
                ->withValue($normalizedLocale)
                ->withExpires(strtotime('+1 year'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false)
                ->withSameSite('lax')
        );

        return $response;
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
        $normalized = substr(strtolower(trim(str_replace('_', '-', $locale))), 0, 2);
        if (\in_array($normalized, ['nb', 'nn'], true)) {
            $normalized = 'no';
        }

        if (\in_array($normalized, $allowedLocales, true)) {
            return $normalized;
        }

        return null;
    }
}
