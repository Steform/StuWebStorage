<?php

namespace App\Service\Share;

use App\Entity\ShareGrant;
use App\Entity\SharedFile;
use App\Repository\ShareGrantRepository;

/**
 * Service ShareAuthorizationService.
 *
 * Sprint 22 (2026-04-28): the public channel and the friends channel are now decoupled. Each
 * channel carries its own activation flag and expiration semantics:
 *   - Public channel  : SharedFile::isPublicShareActive() (driven by is_public + public_expires_at).
 *   - Friends channel : active grants use the database server clock (same as share_grant listing and download checks).
 * Owner access never depends on any expiration. Disabling/expiring one channel never affects the other.
 */
class ShareAuthorizationService
{
    public function __construct(
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly FolderShareAuthorizationService $folderShareAuthorizationService,
    ) {
    }

    /**
     * @brief Check private share access using a precomputed grant flag (legacy contract).
     * @param SharedFile $sharedFile Shared file entity.
     * @param bool $isGranted Access grant status.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function canAccessPrivate(SharedFile $sharedFile, bool $isGranted): bool
    {
        return $isGranted;
    }

    /**
     * @brief Decide private (friends) access for a user from ownership or active grant.
     * @param SharedFile $sharedFile Shared file entity.
     * @param int $requesterUserId Requesting user identifier.
     * @param bool $hasGrant Whether explicit grant exists (legacy callers).
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function canAccessPrivateByUser(SharedFile $sharedFile, int $requesterUserId, bool $hasGrant): bool
    {
        return $this->folderShareAuthorizationService->canAccessFileViaFriends($sharedFile, $requesterUserId);
    }

    /**
     * @brief Check whether the shared file is currently exposed through its public channel.
     * @param SharedFile $sharedFile Shared file entity.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function isPublic(SharedFile $sharedFile): bool
    {
        return $sharedFile->isPublicShareActive();
    }

    /**
     * @brief Tell whether the public channel is enabled but past its own expiration instant.
     * @param SharedFile $sharedFile Shared file entity.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function isPublicExpired(SharedFile $sharedFile): bool
    {
        return $sharedFile->isPublicExpired();
    }

    /**
     * @brief Legacy bridge kept for callers that still query a single file-level expiration flag.
     * @param SharedFile $sharedFile Shared file entity.
     * @return bool
     * @date 2026-04-28
     * @author Stephane H.
     * @deprecated since Sprint 22 (2026-04-28). Channels are independent: use isPublicExpired() for the public channel and ShareGrant::isExpired() for friends.
     */
    public function isFileExpired(SharedFile $sharedFile): bool
    {
        return $this->isPublicExpired($sharedFile);
    }

    /**
     * @brief Tell whether a grant is past its own expiration instant.
     * @param ShareGrant $grant Share grant entity.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function isGrantExpired(ShareGrant $grant): bool
    {
        return !$this->shareGrantRepository->isFriendsGrantActiveAtDatabaseNow($grant->getSharedFileId(), $grant->getGranteeUserId());
    }

    /**
     * @brief Filter a grantee id list to keep only the ones whose grant is still active.
     * @param int $sharedFileId Shared file identifier.
     * @param array<int, int> $granteeUserIds Candidate grantee identifiers.
     * @return array<int, int>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function filterActiveGranteeIds(int $sharedFileId, array $granteeUserIds): array
    {
        if ($granteeUserIds === []) {
            return [];
        }
        $activeOnFile = $this->shareGrantRepository->findActiveGranteeIdsBySharedFile($sharedFileId);
        $activeSet = array_flip($activeOnFile);
        $active = [];
        foreach ($granteeUserIds as $granteeUserId) {
            $id = (int) $granteeUserId;
            if (isset($activeSet[$id])) {
                $active[] = $id;
            }
        }

        return $active;
    }
}
