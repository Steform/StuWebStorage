<?php

namespace App\Controller;

use App\Service\Auth\UserInvitationService;
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
 * Controller InvitationActivationController.
 */
class InvitationActivationController
{
    private const CSRF_ACTIVATE = 'invite_activate_submit';

    /**
     * @brief Build invitation activation controller.
     * @param UserInvitationService $userInvitationService User invitation service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param RateLimiterFactory $inviteActivationLimiter Invitation activation limiter factory.
     * @param UrlGeneratorInterface $urlGenerator Router URL generator.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserInvitationService $userInvitationService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RateLimiterFactory $inviteActivationLimiter,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @brief Render invitation activation form.
     * @param string $token Plain invitation token.
     * @param Request $request HTTP request.
     * @param Environment $twig Twig environment.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/invite/activate/{token}', name: 'invite_activate', methods: ['GET'])]
    public function index(string $token, Request $request, Environment $twig): Response
    {
        $invitationLocale = $this->userInvitationService->resolveActivationLocaleForToken(
            $token,
            (string) $request->query->get('lang', '')
        );
        $requestedLang = strtolower(trim((string) $request->query->get('lang', '')));
        if ($requestedLang !== $invitationLocale) {
            return new RedirectResponse($this->buildActivationUrl($token, $invitationLocale));
        }

        return new Response($twig->render('invitation/activate.html.twig', [
            'token' => $token,
            'csrfActivate' => self::CSRF_ACTIVATE,
            'invitationLocale' => $invitationLocale,
        ]));
    }

    /**
     * @brief Finalize invitation activation with password setup.
     * @param string $token Plain invitation token.
     * @param Request $request HTTP request payload.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/invite/activate/{token}', name: 'invite_activate_submit', methods: ['POST'])]
    public function activate(string $token, Request $request): Response
    {
        $invitationLocale = $this->userInvitationService->resolveActivationLocaleForToken(
            $token,
            (string) $request->request->get('lang', $request->query->get('lang', ''))
        );

        $tokenValue = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_ACTIVATE, $tokenValue))) {
            $this->addRequestFlash($request, 'danger', 'invite.invalid_payload');

            return new RedirectResponse($this->buildActivationUrl($token, $invitationLocale));
        }

        $limiter = $this->inviteActivationLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            $this->addRequestFlash($request, 'danger', 'invite.rate_limited');

            return new RedirectResponse($this->buildActivationUrl($token, $invitationLocale));
        }

        $newPassword = trim((string) $request->request->get('password', ''));
        if ($newPassword === '') {
            $this->addRequestFlash($request, 'danger', 'invite.activate.password_required');

            return new RedirectResponse($this->buildActivationUrl($token, $invitationLocale));
        }

        $activatedLocale = $this->userInvitationService->activateInvitation($token, $newPassword);
        if ($activatedLocale === null) {
            $this->addRequestFlash($request, 'danger', 'invite.activate.invalid_or_expired');

            return new RedirectResponse($this->buildActivationUrl($token, $invitationLocale));
        }

        $this->addRequestFlash($request, 'success', 'invite.activate.success');

        return new RedirectResponse($this->urlGenerator->generate('app_login', ['lang' => $activatedLocale]));
    }

    /**
     * @brief Build activation page URL with locale query parameter.
     * @param string $token Plain invitation token.
     * @param string $locale Invitation locale code.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildActivationUrl(string $token, string $locale): string
    {
        return $this->urlGenerator->generate('invite_activate', [
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
     * @date 2026-04-28
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
