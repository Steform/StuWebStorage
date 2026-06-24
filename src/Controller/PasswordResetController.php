<?php

namespace App\Controller;

use App\Service\Auth\UserPasswordResetService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Controller PasswordResetController.
 */
class PasswordResetController
{
    private const CSRF_FORGOT = 'password_forgot_submit';

    private const CSRF_RESET = 'password_reset_submit';

    /**
     * @brief Build password reset controller.
     * @param UserPasswordResetService $userPasswordResetService Password reset service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param RateLimiterFactory $passwordResetRequestLimiter Password reset request limiter factory.
     * @param RateLimiterFactory $passwordResetCompleteLimiter Password reset completion limiter factory.
     * @param UrlGeneratorInterface $urlGenerator Router URL generator.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserPasswordResetService $userPasswordResetService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RateLimiterFactory $passwordResetRequestLimiter,
        private readonly RateLimiterFactory $passwordResetCompleteLimiter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @brief Render forgot password form.
     * @param Environment $twig Twig environment.
     * @return Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/password/forgot', name: 'app_password_forgot', methods: ['GET'])]
    public function forgotForm(Environment $twig): Response
    {
        return new Response($twig->render('security/forgot_password.html.twig', [
            'csrfForgot' => self::CSRF_FORGOT,
        ]));
    }

    /**
     * @brief Submit forgot password request.
     * @param Request $request HTTP request.
     * @return Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/password/forgot', name: 'app_password_forgot_submit', methods: ['POST'])]
    public function forgotSubmit(Request $request): Response
    {
        $tokenValue = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_FORGOT, $tokenValue))) {
            $this->addRequestFlash($request, 'danger', 'password_reset.invalid_payload');

            return new RedirectResponse($this->urlGenerator->generate('app_password_forgot'));
        }

        $limiter = $this->passwordResetRequestLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            $this->addRequestFlash($request, 'danger', 'password_reset.rate_limited');

            return new RedirectResponse($this->urlGenerator->generate('app_password_forgot'));
        }

        $email = trim((string) $request->request->get('email', ''));
        if ($email === '') {
            $this->addRequestFlash($request, 'danger', 'password_reset.invalid_payload');

            return new RedirectResponse($this->urlGenerator->generate('app_password_forgot'));
        }

        try {
            $this->userPasswordResetService->requestPasswordReset($email, $request->getLocale());
        } catch (\RuntimeException) {
            $this->addRequestFlash($request, 'danger', 'password_reset.email_send_failed');

            return new RedirectResponse($this->urlGenerator->generate('app_password_forgot'));
        }

        $this->addRequestFlash($request, 'success', 'password_reset.requested');

        return new RedirectResponse($this->urlGenerator->generate('app_login', ['lang' => $request->getLocale()]));
    }

    /**
     * @brief Render password reset form.
     * @param string $token Plain reset token.
     * @param Request $request HTTP request.
     * @param Environment $twig Twig environment.
     * @return Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/password/reset/{token}', name: 'app_password_reset', methods: ['GET'])]
    public function resetForm(string $token, Request $request, Environment $twig): Response
    {
        $resetLocale = $this->userPasswordResetService->resolveResetLocaleForToken(
            $token,
            (string) $request->query->get('lang', '')
        );
        $requestedLang = strtolower(trim((string) $request->query->get('lang', '')));
        if ($requestedLang !== $resetLocale) {
            return new RedirectResponse($this->urlGenerator->generate('app_password_reset', [
                'token' => $token,
                'lang' => $resetLocale,
            ]));
        }

        return new Response($twig->render('security/reset_password.html.twig', [
            'token' => $token,
            'csrfReset' => self::CSRF_RESET,
            'resetLocale' => $resetLocale,
        ]));
    }

    /**
     * @brief Submit new password for reset token.
     * @param string $token Plain reset token.
     * @param Request $request HTTP request.
     * @return Response
     * @date 2026-06-24
     * @author Stephane H.
     */
    #[Route('/password/reset/{token}', name: 'app_password_reset_submit', methods: ['POST'])]
    public function resetSubmit(string $token, Request $request): Response
    {
        $resetLocale = $this->userPasswordResetService->resolveResetLocaleForToken(
            $token,
            (string) $request->request->get('lang', $request->query->get('lang', ''))
        );

        $tokenValue = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_RESET, $tokenValue))) {
            $this->addRequestFlash($request, 'danger', 'password_reset.invalid_payload');

            return new RedirectResponse($this->buildResetUrl($token, $resetLocale));
        }

        $limiter = $this->passwordResetCompleteLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            $this->addRequestFlash($request, 'danger', 'password_reset.rate_limited');

            return new RedirectResponse($this->buildResetUrl($token, $resetLocale));
        }

        $newPassword = trim((string) $request->request->get('password', ''));
        if ($newPassword === '') {
            $this->addRequestFlash($request, 'danger', 'password_reset.password_required');

            return new RedirectResponse($this->buildResetUrl($token, $resetLocale));
        }

        $completedLocale = $this->userPasswordResetService->completePasswordReset($token, $newPassword);
        if ($completedLocale === null) {
            $this->addRequestFlash($request, 'danger', 'password_reset.invalid_or_expired');

            return new RedirectResponse($this->buildResetUrl($token, $resetLocale));
        }

        $this->addRequestFlash($request, 'success', 'password_reset.completed');

        return new RedirectResponse($this->urlGenerator->generate('app_login', ['lang' => $completedLocale]));
    }

    /**
     * @brief Build reset page URL with locale query parameter.
     * @param string $token Plain reset token.
     * @param string $locale Reset locale code.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildResetUrl(string $token, string $locale): string
    {
        return $this->urlGenerator->generate('app_password_reset', [
            'token' => $token,
            'lang' => $locale,
        ]);
    }

    /**
     * @brief Add one flash message when request session exists.
     * @param Request $request Current HTTP request.
     * @param string $type Flash type key.
     * @param string $message Translation key for message.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function addRequestFlash(Request $request, string $type, string $message): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->getFlashBag()->add($type, $message);
    }
}
