<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Auth\UserInvitationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller UserInvitationController.
 */
class UserInvitationController extends AbstractController
{
    private const CSRF_INVITE = 'admin_user_invite';

    /**
     * @brief Build user invitation admin controller.
     * @param UserInvitationService $userInvitationService User invitation service.
     * @param Security $security Security helper.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param RateLimiterFactory $adminInviteLimiter Admin invitation limiter factory.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserInvitationService $userInvitationService,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly RateLimiterFactory $adminInviteLimiter
    ) {
    }

    /**
     * @brief Render invitation form for administrators.
     * @param Request $request HTTP request.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/users/invite', name: 'admin_users_invite', methods: ['GET'])]
    public function inviteForm(Request $request): Response
    {
        return $this->render('admin/users/invite.html.twig', [
            'currentLocale' => $request->getLocale(),
            'csrfInvite' => self::CSRF_INVITE,
        ]);
    }

    /**
     * @brief Invite a user from admin area (JSON API or HTML form).
     * @param Request $request HTTP request payload.
     * @return JsonResponse|RedirectResponse
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[IsGranted('ROLE_ADMIN')]
    #[Route('/admin/users/invite', name: 'admin_user_invite', methods: ['POST'])]
    public function invite(Request $request): JsonResponse|RedirectResponse
    {
        $wantsJson = $this->wantsJsonResponse($request);
        $limiter = $this->adminInviteLimiter->create($request->getClientIp() ?? 'unknown');
        if (!$limiter->consume()->isAccepted()) {
            if ($wantsJson) {
                return new JsonResponse(['status' => 'error', 'message' => 'invite.rate_limited'], 429);
            }
            $this->addFlash('danger', 'invite.rate_limited');

            return $this->redirectToRoute('admin_users_invite');
        }

        if (!$wantsJson) {
            $tokenValue = (string) $request->request->get('_csrf_token', '');
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_INVITE, $tokenValue))) {
                $this->addFlash('danger', 'admin.users.error.invalid_payload');

                return $this->redirectToRoute('admin_users_invite');
            }
        }

        $email = trim((string) $request->request->get('email', ''));
        $pseudonym = trim((string) $request->request->get('pseudonym', ''));
        $requestedLocale = trim((string) $request->request->get('locale', ''));
        if ($email === '') {
            if ($wantsJson) {
                return new JsonResponse(['status' => 'error', 'message' => 'invite.invalid_payload'], 400);
            }
            $this->addFlash('danger', 'invite.invalid_payload');

            return $this->redirectToRoute('admin_users_invite');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User || $user->getId() === null) {
            if ($wantsJson) {
                return new JsonResponse(['status' => 'error', 'message' => 'invite.unauthorized'], 403);
            }
            $this->addFlash('danger', 'invite.unauthorized');

            return $this->redirectToRoute('admin_users_invite');
        }

        try {
            $activationUrl = $this->userInvitationService->inviteUser(
                $email,
                $pseudonym,
                (int) $user->getId(),
                $requestedLocale !== '' ? $requestedLocale : $request->getLocale()
            );
        } catch (\RuntimeException $exception) {
            $mappedKey = $this->mapInvitationExceptionToTranslationKey($exception->getMessage());
            if ($wantsJson) {
                return new JsonResponse(['status' => 'error', 'message' => $mappedKey], 409);
            }
            $this->addFlash('danger', $mappedKey);

            return $this->redirectToRoute('admin_users_invite');
        }

        if ($wantsJson) {
            return new JsonResponse([
                'status' => 'ok',
                'message' => 'invite.created',
                'activationUrl' => $activationUrl,
            ], 201);
        }

        $this->addFlash('success', 'admin.invite.flash.sent');

        return $this->redirectToRoute('admin_users_invite');
    }

    /**
     * @brief Map invitation runtime messages to translation keys.
     * @param string $message Raw exception message.
     * @return string
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function mapInvitationExceptionToTranslationKey(string $message): string
    {
        return match ($message) {
            'invitation.user_already_exists' => 'invite.user_already_exists',
            'invitation.user_persist_failed' => 'invite.user_persist_failed',
            'invitation.email_send_failed' => 'invite.email_send_failed',
            default => 'invite.invalid_payload',
        };
    }

    /**
     * @brief Detect JSON-first invitation clients.
     * @param Request $request HTTP request.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    private function wantsJsonResponse(Request $request): bool
    {
        $accept = (string) $request->headers->get('Accept', '');
        $format = $request->getContentTypeFormat();

        return str_contains($accept, 'application/json') || $format === 'json';
    }
}
