<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Auth\TotpChallengeService;
use App\Service\Auth\TrustedDeviceService;
use App\Service\Notification\TotpEmailNotificationService;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\LogicException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Twig\Environment;

/**
 * Controller SecurityUiController.
 */
class SecurityUiController
{
    /**
     * @brief Build security UI controller.
     * @param Security $security Security helper.
     * @param TotpChallengeService $totpChallengeService TOTP challenge service.
     * @param TotpEmailNotificationService $totpEmailNotificationService TOTP email sender.
     * @param TrustedDeviceService $trustedDeviceService Trusted device service.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly Security $security,
        private readonly TotpChallengeService $totpChallengeService,
        private readonly TotpEmailNotificationService $totpEmailNotificationService,
        private readonly TrustedDeviceService $trustedDeviceService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager
    ) {
    }

    /**
     * @brief Render login page.
     * @param Environment $twig Twig environment.
     * @param AuthenticationUtils $authenticationUtils Security authentication utils.
     * @param Security $security Security helper.
     * @return Response
     * @date 2026-04-22
     * @author Stephane H.
     */
    #[Route('/login', name: 'app_login', methods: ['GET'])]
    public function login(Environment $twig, AuthenticationUtils $authenticationUtils): Response
    {
        $authenticatedUser = $this->security->getUser();
        if ($authenticatedUser instanceof User) {
            $location = in_array('ROLE_ADMIN', $authenticatedUser->getRoles(), true) ? '/dashboard' : '/';

            return new Response('', Response::HTTP_FOUND, ['Location' => $location]);
        }

        return new Response($twig->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]));
    }

    /**
     * @brief Expose login check route for security authenticator.
     * @param void No input parameter.
     * @return never
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/login/check', name: 'app_login_check', methods: ['POST'])]
    public function loginCheck(): never
    {
        throw new LogicException('Login check is handled by Symfony security authenticator.');
    }

    /**
     * @brief Redirect accidental GET access on login check endpoint to login form.
     * @param void No input parameter.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/login/check', name: 'app_login_check_get', methods: ['GET'])]
    public function loginCheckGet(): Response
    {
        return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
    }

    /**
     * @brief Render second-factor TOTP form for pending login.
     * @param Environment $twig Twig environment.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/login/totp', name: 'app_login_totp', methods: ['GET'])]
    public function loginTotp(Environment $twig, Request $request): Response
    {
        $user = $this->security->getUser();
        $pendingUserId = $request->hasSession() ? (int) $request->getSession()->get('auth.totp_pending_user_id', 0) : 0;
        if (!$user instanceof User || $user->getId() !== $pendingUserId) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
        }

        return new Response($twig->render('security/login_totp.html.twig', [
            'error' => $request->query->get('error', ''),
            'resendState' => $this->totpChallengeService->getResendState($user->getEmail()),
            'resendCooldownSeconds' => $this->totpChallengeService->getResendCooldownSeconds(),
        ]));
    }

    /**
     * @brief Resend login TOTP code by email with rate limiting.
     * @param Request $request Current request.
     * @return JsonResponse
     * @date 2026-06-15
     * @author Stephane H.
     */
    #[Route('/login/totp/resend', name: 'app_login_totp_resend', methods: ['POST'])]
    public function loginTotpResend(Request $request): JsonResponse
    {
        $user = $this->security->getUser();
        $pendingUserId = $request->hasSession() ? (int) $request->getSession()->get('auth.totp_pending_user_id', 0) : 0;
        if (!$user instanceof User || $user->getId() !== $pendingUserId) {
            return new JsonResponse(['status' => 'error', 'message' => 'auth.totp.invalid'], 403);
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('login_totp_resend', $csrfToken))) {
            return new JsonResponse(['status' => 'error', 'message' => 'auth.totp.invalid'], 400);
        }

        try {
            $code = $this->totpChallengeService->resendLoginChallenge($user->getEmail());
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $retryAfterSeconds = $this->totpChallengeService->getRetryAfterSeconds($user->getEmail());
            if ($message === 'auth.totp.challenge.cooldown') {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => $message,
                    'retryAfterSeconds' => $retryAfterSeconds,
                ], 429);
            }
            if ($message === 'auth.totp.challenge.rate_limited') {
                return new JsonResponse([
                    'status' => 'error',
                    'message' => $message,
                ], 429);
            }

            return new JsonResponse(['status' => 'error', 'message' => 'auth.totp.invalid'], 400);
        } catch (InvalidArgumentException) {
            return new JsonResponse(['status' => 'error', 'message' => 'auth.totp.invalid'], 400);
        }

        $this->totpEmailNotificationService->sendTotpCode($user->getEmail(), $code);

        return new JsonResponse([
            'status' => 'ok',
            'message' => 'auth.totp.challenge.sent',
            'retryAfterSeconds' => $this->totpChallengeService->getResendCooldownSeconds(),
        ]);
    }

    /**
     * @brief Validate second-factor TOTP and finish trusted login.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/login/totp', name: 'app_login_totp_submit', methods: ['POST'])]
    public function loginTotpSubmit(Request $request): Response
    {
        $user = $this->security->getUser();
        $pendingUserId = $request->hasSession() ? (int) $request->getSession()->get('auth.totp_pending_user_id', 0) : 0;
        if (!$user instanceof User || $user->getId() !== $pendingUserId) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/login']);
        }

        $totpCode = trim((string) $request->request->get('totp', ''));
        if (!$this->totpChallengeService->validateLoginChallenge($user->getEmail(), $totpCode)) {
            return new Response('', Response::HTTP_FOUND, ['Location' => '/login/totp?error=auth.totp.invalid']);
        }

        if ($request->hasSession() && (bool) $request->getSession()->get('auth.totp_remember', false)) {
            $this->trustedDeviceService->trustDevice((int) $user->getId(), $request);
        }

        if ($request->hasSession()) {
            $request->getSession()->remove('auth.totp_pending_user_id');
            $request->getSession()->remove('auth.totp_remember');
            $request->getSession()->set('auth.session_version', $user->getSessionVersion());
        }

        return new Response('', Response::HTTP_FOUND, ['Location' => '/']);
    }

    /**
     * @brief Bootstrap pending TOTP state after password success.
     * @param Request $request Current request.
     * @param User $user Authenticated user.
     * @param bool $rememberRequested Remember-me explicit choice.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function startTotpStep(Request $request, User $user, bool $rememberRequested): void
    {
        if (!$request->hasSession()) {
            return;
        }

        $request->getSession()->set('auth.totp_pending_user_id', (int) $user->getId());
        $request->getSession()->set('auth.totp_remember', $rememberRequested);
        $code = (string) random_int(100000, 999999);
        $this->totpChallengeService->createLoginChallenge($user->getEmail(), $code);
        $this->totpEmailNotificationService->sendTotpCode($user->getEmail(), $code);
    }

    /**
     * @brief Redirect logout route to home.
     * @param void No input parameter.
     * @return never
     * @date 2026-04-22
     * @author Stephane H.
     */
    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new LogicException('Logout is handled by Symfony security firewall.');
    }
}
