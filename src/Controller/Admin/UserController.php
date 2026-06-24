<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\RoleGovernanceService;
use App\Service\Admin\TrustedDeviceAdminService;
use App\Service\Admin\UserManagementService;
use App\Service\Auth\UserInvitationService;
use App\Service\File\UserStorageQuotaService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

/**
 * Controller UserController.
 */
#[IsGranted('ROLE_ADMIN')]
class UserController
{
    private const CSRF_DELETE = 'admin_users_hard_delete';

    /**
     * @brief Build admin user controller.
     * @param UserManagementService $userManagementService User management service.
     * @param RoleGovernanceService $roleGovernanceService Role governance service.
     * @param TrustedDeviceAdminService $trustedDeviceAdminService Trusted device admin service.
     * @param Security $security Security helper.
     * @param CsrfTokenManagerInterface $csrfTokenManager CSRF token manager.
     * @param UserStorageQuotaService $userStorageQuotaService User storage quota service.
     * @param UserInvitationService $userInvitationService User invitation service.
     * @param list<string> $supportedLocales Supported invitation email locales.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly RoleGovernanceService $roleGovernanceService,
        private readonly TrustedDeviceAdminService $trustedDeviceAdminService,
        private readonly Security $security,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly UserInvitationService $userInvitationService,
        private readonly array $supportedLocales = ['fr', 'en', 'de', 'lt', 'no'],
    ) {
    }

    /**
     * @brief Render paginated admin user list.
     * @param Environment $twig Twig environment.
     * @param Request $request Current request.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users', name: 'admin_users_index', methods: ['GET'])]
    public function index(Environment $twig, Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $search = trim((string) $request->query->get('q', ''));
        $result = $this->userManagementService->listUsers($page, 20, $search);
        $userIds = array_values(array_filter(array_map(
            static fn (User $user): ?int => $user->getId(),
            $result['items']
        )));
        $pendingInvitationUserIds = array_flip($this->userInvitationService->findUserIdsWithPendingInvitation($userIds));

        return new Response($twig->render('admin/users/index.html.twig', [
            'users' => $result['items'],
            'total' => $result['total'],
            'page' => $page,
            'search' => $search,
            'pendingInvitationUserIds' => $pendingInvitationUserIds,
        ]));
    }

    /**
     * @brief Render one user detail page.
     * @param Environment $twig Twig environment.
     * @param Request $request Current request.
     * @param int $id User identifier.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}', name: 'admin_users_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Environment $twig, Request $request, int $id): Response
    {
        $targetUser = $this->findUser($id);
        if (!$targetUser instanceof User) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        return new Response($twig->render('admin/users/show.html.twig', [
            'targetUser' => $targetUser,
            'trustedDevices' => $this->trustedDeviceAdminService->listByUserId((int) $targetUser->getId()),
            'allowedRoles' => $this->roleGovernanceService->getAllowedRoles(),
            'storageUsedBytes' => $this->userStorageQuotaService->sumUsedBytesByOwner((int) $targetUser->getId()),
            'storageQuotaBytes' => $this->userStorageQuotaService->resolveEffectiveQuotaBytes($targetUser),
            'storageQuotaSource' => $this->userStorageQuotaService->resolveQuotaSource($targetUser),
            'storageQuotaGiB' => $this->userStorageQuotaService->formatQuotaGiBForAdmin($targetUser->getStorageQuotaBytes()),
            'hasPendingInvitation' => $this->userInvitationService->hasPendingInvitation((int) $targetUser->getId()),
            'csrfResendInvite' => 'admin_user_resend_invite',
            'supportedLocales' => $this->supportedLocales,
            'selectedInvitationLocale' => $this->userInvitationService->resolvePendingInvitationLocale((int) $targetUser->getId())
                ?? $this->resolveDefaultInvitationLocale($request),
        ]));
    }

    /**
     * @brief Update one user profile from admin form.
     * @param Request $request Current request.
     * @param int $id User identifier.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/edit', name: 'admin_users_edit', methods: ['POST'])]
    public function edit(Request $request, int $id): Response
    {
        $targetUser = $this->findUser($id);
        $actorUser = $this->security->getUser();
        if (!$targetUser instanceof User || !$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $quotaInput = $this->userStorageQuotaService->parseAdminGiBInput((string) $request->request->get('storage_quota_gib', ''));
        if ($quotaInput['errorKey'] !== null) {
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('danger', $quotaInput['errorKey']);
            }

            return new RedirectResponse('/admin/users/'.$id);
        }

        $errorKey = $this->userManagementService->updateProfile(
            $targetUser,
            (string) $request->request->get('email', ''),
            (string) $request->request->get('pseudonym', ''),
            $request->request->has('active'),
            $quotaInput['quotaBytes'],
            (int) $actorUser->getId()
        );
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.profile_updated');
        }

        return new RedirectResponse('/admin/users/'.$id);
    }

    /**
     * @brief Hard delete target user from admin detail page.
     * @param Request $request Current request.
     * @param int $id Target user identifier.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/delete', name: 'admin_users_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, int $id): Response
    {
        $targetUser = $this->findUser($id);
        $actorUser = $this->security->getUser();
        if (!$targetUser instanceof User || !$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $csrfToken = (string) $request->request->get('_csrf_token', '');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_DELETE, $csrfToken))) {
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('danger', 'admin.users.error.invalid_payload');
            }

            return new RedirectResponse('/admin/users/'.$id);
        }

        $confirmation = trim((string) $request->request->get('confirm_phrase', ''));
        if ($confirmation !== 'DELETE') {
            if ($request->hasSession()) {
                $request->getSession()->getFlashBag()->add('danger', 'admin.users.error.hard_delete_confirmation_invalid');
            }

            return new RedirectResponse('/admin/users/'.$id);
        }

        $reason = trim((string) $request->request->get('hard_delete_reason', ''));
        $errorKey = $this->userManagementService->hardDeleteUser($actorUser, $targetUser, $reason);
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.hard_deleted');
        }

        return new RedirectResponse('/admin/users');
    }

    /**
     * @brief Update target user roles from admin form.
     * @param Request $request Current request.
     * @param int $id User identifier.
     * @return Response
     * @date 2026-04-28
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/roles', name: 'admin_users_roles', methods: ['POST'])]
    public function updateRoles(Request $request, int $id): Response
    {
        $targetUser = $this->findUser($id);
        $actorUser = $this->security->getUser();
        if (!$targetUser instanceof User || !$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $payload = $request->request->all();
        $rawRoles = $payload['roles'] ?? [];
        if (is_string($rawRoles)) {
            $rawRoles = [$rawRoles];
        }
        if (!is_array($rawRoles)) {
            $rawRoles = [];
        }
        /** @var array<int, string> $roles */
        $roles = array_values(array_filter($rawRoles, static fn (mixed $role): bool => is_string($role)));
        $errorKey = $this->userManagementService->updateRoles($actorUser, $targetUser, $roles);
        if (is_string($errorKey) && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('danger', $errorKey);
        }
        if ($errorKey === null && $request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.roles_updated');
        }

        return new RedirectResponse('/admin/users/'.$id);
    }

    /**
     * @brief Resolve default invitation email locale for admin forms.
     * @param Request $request Current request.
     * @return string Supported locale code, falling back to English.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function resolveDefaultInvitationLocale(Request $request): string
    {
        $uiLocale = strtolower(trim($request->getLocale()));
        if (in_array($uiLocale, $this->supportedLocales, true)) {
            return $uiLocale;
        }

        return 'en';
    }

    /**
     * @brief Resolve user entity by identifier.
     * @param int $id User identifier.
     * @return User|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    private function findUser(int $id): ?User
    {
        return $id > 0 ? $this->userManagementService->findUserById($id) : null;
    }
}
