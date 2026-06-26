<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\SharedFile;
use App\Repository\FolderShareGrantRepository;
use App\Repository\ShareGrantRepository;

/**
 * Resolves friends-channel access from file grants and folder subtree grants.
 */
final class FolderShareAuthorizationService
{
    public function __construct(
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly FolderShareGrantRepository $folderShareGrantRepository,
    ) {
    }

    /**
     * @brief Decide friends access from ownership, file grant, or folder ancestor grant.
     * @param SharedFile $sharedFile Shared file entity.
     * @param int $requesterUserId Requesting user identifier.
     * @return bool
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function canAccessFileViaFriends(SharedFile $sharedFile, int $requesterUserId): bool
    {
        if ($requesterUserId < 1) {
            return false;
        }
        if ($sharedFile->getOwnerUserId() === $requesterUserId) {
            return true;
        }

        $sharedFileId = (int) $sharedFile->getId();
        if ($sharedFileId > 0 && $this->shareGrantRepository->isFriendsGrantActiveAtDatabaseNow($sharedFileId, $requesterUserId)) {
            return true;
        }

        $fileFolderId = (int) ($sharedFile->getFolder()?->getId() ?? 0);
        if ($fileFolderId < 1) {
            return false;
        }

        return $this->folderShareGrantRepository->hasActiveGrantForFileFolder($requesterUserId, $fileFolderId);
    }
}
