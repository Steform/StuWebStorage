<?php

namespace App\Service\Admin;

use App\Entity\PasswordResetRequest;
use App\Entity\User;
use App\Entity\UserDeletionSnapshot;
use App\Repository\UserRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Service UserManagementService.
 */
class UserManagementService
{
    /**
     * @brief Build user management service.
     * @param UserRepository $userRepository User repository.
     * @param RoleGovernanceService $roleGovernanceService Role governance service.
     * @param TrustedDeviceAdminService $trustedDeviceAdminService Trusted device admin service.
     * @param UserHardDeleteSnapshotService|null $userHardDeleteSnapshotService Hard delete snapshot service.
     * @param UserHardDeleteVaultService|null $userHardDeleteVaultService Hard delete vault service.
     * @param UserHardDeletePurgeService|null $userHardDeletePurgeService Hard delete purge service.
     * @param EntityManagerInterface $entityManager Doctrine entity manager.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly RoleGovernanceService $roleGovernanceService,
        private readonly TrustedDeviceAdminService $trustedDeviceAdminService,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?UserHardDeleteSnapshotService $userHardDeleteSnapshotService = null,
        private readonly ?UserHardDeleteVaultService $userHardDeleteVaultService = null,
        private readonly ?UserHardDeletePurgeService $userHardDeletePurgeService = null,
    ) {
    }

    /**
     * @brief Return paginated user list.
     * @param int $page Current page.
     * @param int $pageSize Page size.
     * @param string $searchTerm Optional search query.
     * @return array{items: list<User>, total: int}
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function listUsers(int $page, int $pageSize, string $searchTerm = ''): array
    {
        return $this->userRepository->paginateUsers($page, $pageSize, $searchTerm);
    }

    /**
     * @brief Find one user by identifier.
     * @param int $userId User identifier.
     * @return User|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findUserById(int $userId): ?User
    {
        return $this->userRepository->find($userId);
    }

    /**
     * @brief Update editable user profile fields.
     * @param User $targetUser User to update.
     * @param string $email Updated email.
     * @param string $pseudonym Updated pseudonym.
     * @param bool $active Updated active flag.
     * @param int|null $storageQuotaBytes Updated storage quota bytes (null inherits default).
     * @param int $adminUserId Admin actor identifier.
     * @return string|null Translation key on failure or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function updateProfile(
        User $targetUser,
        string $email,
        string $pseudonym,
        bool $active,
        ?int $storageQuotaBytes,
        int $adminUserId,
    ): ?string {
        $normalizedEmail = strtolower(trim($email));
        $normalizedPseudonym = trim($pseudonym);
        if ($normalizedEmail === '' || $normalizedPseudonym === '') {
            return 'admin.users.error.invalid_payload';
        }

        if (!$active) {
            $errorKey = $this->roleGovernanceService->validateDeactivation($targetUser);
            if (is_string($errorKey)) {
                return $errorKey;
            }
        }

        $targetUser->setEmail($normalizedEmail);
        $targetUser->setPseudonym($normalizedPseudonym);
        $targetUser->setActive($active);
        $targetUser->setStorageQuotaBytes($storageQuotaBytes);
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Apply role update with governance checks.
     * @param User $actorUser Admin actor.
     * @param User $targetUser Target user.
     * @param array<int, string> $requestedRoles Requested role list.
     * @return string|null Translation key on failure or null.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function updateRoles(User $actorUser, User $targetUser, array $requestedRoles): ?string
    {
        $errorKey = $this->roleGovernanceService->validateRoleChange($actorUser, $targetUser, $requestedRoles);
        if (is_string($errorKey)) {
            return $errorKey;
        }

        $targetUser->setRoles(array_values(array_unique($requestedRoles)));
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Revoke one trusted device from admin area.
     * @param int $actorUserId Admin actor identifier.
     * @param int $targetUserId Target user identifier.
     * @param int $deviceId Device identifier.
     * @return bool
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function revokeTrustedDevice(int $actorUserId, int $targetUserId, int $deviceId): bool
    {
        return $this->trustedDeviceAdminService->revokeOne($targetUserId, $deviceId);
    }

    /**
     * @brief Revoke all trusted devices for one user.
     * @param int $actorUserId Admin actor identifier.
     * @param int $targetUserId Target user identifier.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function revokeAllTrustedDevices(int $actorUserId, int $targetUserId): int
    {
        return $this->trustedDeviceAdminService->revokeAll($targetUserId);
    }

    /**
     * @brief Force password reset for target user.
     * @param int $actorUserId Admin actor identifier.
     * @param User $targetUser Target user.
     * @return string Reset token plain value.
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function forcePasswordReset(int $actorUserId, User $targetUser): string
    {
        $targetUser->setPasswordResetRequired(true);
        $token = bin2hex(random_bytes(32));
        $resetRequest = new PasswordResetRequest(
            (int) $targetUser->getId(),
            hash('sha256', $token),
            (new DateTimeImmutable())->add(new DateInterval('PT1H'))
        );
        $this->entityManager->persist($resetRequest);
        $this->entityManager->flush();

        return $token;
    }

    /**
     * @brief Invalidate active sessions for target user.
     * @param int $actorUserId Admin actor identifier.
     * @param User $targetUser Target user.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function invalidateActiveSessions(int $actorUserId, User $targetUser): void
    {
        $targetUser->bumpSessionVersion();
        $this->revokeAllTrustedDevices($actorUserId, (int) $targetUser->getId());
        $this->entityManager->flush();
    }

    /**
     * @brief Soft delete target user with governance and security revocation.
     * @param User $actorUser Administrator actor.
     * @param User $targetUser Target user to disable.
     * @return string|null Translation key on failure or null.
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function softDeleteUser(User $actorUser, User $targetUser): ?string
    {
        if ($actorUser->getId() !== null && $targetUser->getId() === $actorUser->getId()) {
            return 'admin.users.error.self_soft_delete_forbidden';
        }
        if (!$targetUser->isActive()) {
            return 'admin.users.error.already_inactive';
        }

        $errorKey = $this->roleGovernanceService->validateDeactivation($targetUser);
        if (is_string($errorKey)) {
            return $errorKey;
        }

        $targetUser->setActive(false);
        $targetUser->bumpSessionVersion();
        $this->trustedDeviceAdminService->revokeAll((int) $targetUser->getId());
        $this->entityManager->flush();

        return null;
    }

    /**
     * @brief Hard delete target user with encrypted rollback snapshot.
     * @param User $actorUser Administrator actor.
     * @param User $targetUser Target user to purge.
     * @param string $reason Optional explicit deletion reason.
     * @return string|null Translation key on failure or null.
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function hardDeleteUser(User $actorUser, User $targetUser, string $reason = ''): ?string
    {
        if (
            !$this->userHardDeleteSnapshotService instanceof UserHardDeleteSnapshotService
            || !$this->userHardDeleteVaultService instanceof UserHardDeleteVaultService
            || !$this->userHardDeletePurgeService instanceof UserHardDeletePurgeService
        ) {
            return 'admin.users.error.hard_delete_unavailable';
        }

        if ($actorUser->getId() !== null && $targetUser->getId() === $actorUser->getId()) {
            return 'admin.users.error.self_hard_delete_forbidden';
        }

        if (in_array('ROLE_ADMIN', $targetUser->getRoles(), true) && $targetUser->isActive() && $this->userRepository->countActiveAdmins() <= 1) {
            return 'admin.users.error.last_admin_protected';
        }

        $snapshotPayload = $this->userHardDeleteSnapshotService->buildSnapshotPayload($targetUser);
        $sealedPayload = $this->userHardDeleteVaultService->encryptPayload($snapshotPayload);
        $snapshot = new UserDeletionSnapshot(
            (int) $targetUser->getId(),
            $sealedPayload['ciphertext'],
            $sealedPayload['signature'],
            $sealedPayload['algo'],
            $sealedPayload['keyVersion']
        );
        $this->entityManager->persist($snapshot);
        $this->entityManager->flush();

        $this->userHardDeletePurgeService->purgeFromSnapshot($snapshotPayload);

        return null;
    }
}
