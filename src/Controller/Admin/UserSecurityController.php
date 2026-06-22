<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\Admin\UserManagementService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Controller UserSecurityController.
 */
#[IsGranted('ROLE_ADMIN')]
class UserSecurityController
{
    /**
     * @brief Build admin user security controller.
     * @param UserManagementService $userManagementService User management service.
     * @param Security $security Security helper.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserManagementService $userManagementService,
        private readonly Security $security
    ) {
    }

    /**
     * @brief Revoke one trusted device from user.
     * @param Request $request Current request.
     * @param int $id Target user identifier.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/trusted-devices/revoke', name: 'admin_users_trusted_device_revoke', methods: ['POST'])]
    public function revokeTrustedDevice(Request $request, int $id): Response
    {
        $actorUser = $this->security->getUser();
        if (!$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $deviceId = (int) $request->request->get('device_id', 0);
        $isRevoked = $this->userManagementService->revokeTrustedDevice((int) $actorUser->getId(), $id, $deviceId);
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add($isRevoked ? 'success' : 'danger', $isRevoked ? 'admin.users.success.device_revoked' : 'admin.users.error.device_not_found');
        }

        return new RedirectResponse('/admin/users/'.$id);
    }

    /**
     * @brief Revoke all trusted devices from user.
     * @param Request $request Current request.
     * @param int $id Target user identifier.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/trusted-devices/revoke-all', name: 'admin_users_trusted_device_revoke_all', methods: ['POST'])]
    public function revokeAllTrustedDevices(Request $request, int $id): Response
    {
        $actorUser = $this->security->getUser();
        if (!$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $count = $this->userManagementService->revokeAllTrustedDevices((int) $actorUser->getId(), $id);
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.devices_revoked');
        }

        return new RedirectResponse('/admin/users/'.$id.'?revoked='.$count);
    }

    /**
     * @brief Force password reset workflow for target user.
     * @param Request $request Current request.
     * @param int $id Target user identifier.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/force-password-reset', name: 'admin_users_force_password_reset', methods: ['POST'])]
    public function forcePasswordReset(Request $request, int $id): Response
    {
        $targetUser = $this->userManagementService->findUserById($id);
        $actorUser = $this->security->getUser();
        if (!$targetUser instanceof User || !$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->userManagementService->forcePasswordReset((int) $actorUser->getId(), $targetUser);
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.password_reset_forced');
        }

        return new RedirectResponse('/admin/users/'.$id);
    }

    /**
     * @brief Invalidate target user active sessions.
     * @param Request $request Current request.
     * @param int $id Target user identifier.
     * @return Response
     * @date 2026-04-23
     * @author Stephane H.
     */
    #[Route('/admin/users/{id}/invalidate-sessions', name: 'admin_users_invalidate_sessions', methods: ['POST'])]
    public function invalidateSessions(Request $request, int $id): Response
    {
        $targetUser = $this->userManagementService->findUserById($id);
        $actorUser = $this->security->getUser();
        if (!$targetUser instanceof User || !$actorUser instanceof User || $actorUser->getId() === null) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $this->userManagementService->invalidateActiveSessions((int) $actorUser->getId(), $targetUser);
        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('success', 'admin.users.success.sessions_invalidated');
        }

        return new RedirectResponse('/admin/users/'.$id);
    }
}
