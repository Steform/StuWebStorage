<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Repository\SharedFileRepository;

/**
 * Service FolderPropertiesService.
 */
class FolderPropertiesService
{
    public function __construct(
        private readonly FolderTreeService $folderTreeService,
        private readonly SharedFileRepository $sharedFileRepository,
    ) {
    }

    /**
     * @brief Build recursive folder properties for one owner-scoped folder subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @return array{totalBytes:int,totalFiles:int,totalSubfolders:int}
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function buildRecursiveProperties(int $ownerUserId, Folder $folder): array
    {
        $folderIds = $this->extractFolderIds($ownerUserId, $folder);

        $totalFiles = $this->sharedFileRepository->countByOwnerAndFolderIds($ownerUserId, $folderIds);
        $totalBytes = $this->sharedFileRepository->sumByteSizeByOwnerAndFolderIds($ownerUserId, $folderIds);

        return [
            'totalBytes' => $totalBytes,
            'totalFiles' => $totalFiles,
            'totalSubfolders' => max(0, count($folderIds) - 1),
        ];
    }

    /**
     * @brief Build recursive sharing status for one owner-scoped folder subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @return array{publicActive:bool,friendsActive:bool,filesInSubtree:int,hasPublicExpiration:bool} hasPublicExpiration is true only when publicActive is true and a finite active public expiry exists in subtree (listing clock).
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function buildRecursiveShareState(int $ownerUserId, Folder $folder): array
    {
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        $folderIds = [];
        $hasEffectiveFolderPublicPolicy = false;
        $hasFolderFriendsPolicy = false;
        $folderShowsPublicExpirationClock = false;
        foreach ($folders as $subFolder) {
            $id = $subFolder->getId();
            if ($id !== null && $id > 0) {
                $folderIds[] = $id;
            }
            if ($subFolder->isPublicShareEffectivelyActive()) {
                $hasEffectiveFolderPublicPolicy = true;
            }
            if (
                $subFolder->isPublicShareEffectivelyActive()
                && $subFolder->getPublicShareExpiresAt() !== null
            ) {
                $folderShowsPublicExpirationClock = true;
            }
            if ($subFolder->getFriendsShareUserIds() !== []) {
                $hasFolderFriendsPolicy = true;
            }
        }
        $filesInSubtree = $this->sharedFileRepository->countByOwnerAndFolderIds($ownerUserId, $folderIds);
        $publicCount = $this->sharedFileRepository->countActivePublicByOwnerAndFolderIds($ownerUserId, $folderIds);
        $friendsCount = $this->sharedFileRepository->countActiveFriendsByOwnerAndFolderIds($ownerUserId, $folderIds);
        $filesFiniteActivePublic = $this->sharedFileRepository->countActivePublicWithFiniteExpiryByOwnerAndFolderIds($ownerUserId, $folderIds);

        $publicActive = $publicCount > 0 || $hasEffectiveFolderPublicPolicy;

        // Friends "Oui" only when at least one active grant exists on a file, or folder subtree is empty but folder-level friend intents remain (no stale JSON while files exist).
        $friendsActive = $friendsCount > 0 || ($filesInSubtree === 0 && $hasFolderFriendsPolicy);

        $hasPublicExpirationListingClock = $publicActive
            && ($filesFiniteActivePublic > 0 || $folderShowsPublicExpirationClock);

        return [
            'publicActive' => $publicActive,
            'friendsActive' => $friendsActive,
            'filesInSubtree' => $filesInSubtree,
            'hasPublicExpiration' => $hasPublicExpirationListingClock,
        ];
    }

    /**
     * @brief Extract non-empty folder identifiers for one owner-scoped subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @return array<int, int>
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function extractFolderIds(int $ownerUserId, Folder $folder): array
    {
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        $folderIds = [];
        foreach ($folders as $subFolder) {
            $id = $subFolder->getId();
            if ($id !== null && $id > 0) {
                $folderIds[] = $id;
            }
        }

        return $folderIds;
    }
}
