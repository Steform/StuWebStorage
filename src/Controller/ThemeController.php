<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller ThemeController.
 */
class ThemeController extends AbstractController
{
    /**
     * @brief Persist selected theme and redirect back.
     * @param string $theme Theme candidate.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/theme/{theme}', name: 'theme_switch', methods: ['GET'])]
    public function switch(string $theme, Request $request): Response
    {
        $normalizedTheme = $this->normalizeTheme($theme);
        if ($normalizedTheme === null) {
            $this->addFlash('warning', 'theme.invalid');
            $normalizedTheme = 'light';
        }

        if ($request->hasSession()) {
            $request->getSession()->set('_theme', $normalizedTheme);
        }

        $referer = (string) $request->headers->get('referer', '');
        $targetUrl = $referer !== '' ? $referer : $this->generateUrl('app_home');

        $response = new RedirectResponse($targetUrl);
        $response->headers->setCookie(
            Cookie::create('site_theme')
                ->withValue($normalizedTheme)
                ->withExpires(strtotime('+1 year'))
                ->withPath('/')
                ->withSecure($request->isSecure())
                ->withHttpOnly(false)
                ->withSameSite('lax')
        );

        return $response;
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
}
