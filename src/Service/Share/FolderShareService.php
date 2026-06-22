<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Repository\SharedFileRepository;

/**
 * Service FolderShareService.
 */
class FolderShareService
{
    public function __construct(
        private readonly FolderTreeService $folderTreeService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly PublicShareService $publicShareService,
        private readonly FriendsShareService $friendsShareService,
    ) {
    }

    /**
     * @brief Apply public share state recursively on all files from a folder subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @param bool $enabled Target public state.
     * @param \DateTimeImmutable|null $expiresAt Optional expiration.
     * @return int
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function applyPublicRecursive(int $ownerUserId, Folder $folder, bool $enabled, ?\DateTimeImmutable $expiresAt): int
    {
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        $updated = 0;
        foreach ($folders as $subFolder) {
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerUserId, 'folder' => $subFolder]);
            foreach ($files as $file) {
                if ($enabled) {
                    $this->publicShareService->enablePublic($file, $expiresAt);
                } else {
                    $this->publicShareService->disablePublic($file);
                }
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * @brief Apply friends share intents recursively on all files from a folder subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @param array<int, array{user_id: int, expires_at: string}> $grantees Friends intents.
     * @param bool $replaceExisting Replace mode flag.
     * @return int
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function applyFriendsRecursive(int $ownerUserId, Folder $folder, array $grantees, bool $replaceExisting): int
    {
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        $updated = 0;
        foreach ($folders as $subFolder) {
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerUserId, 'folder' => $subFolder]);
            foreach ($files as $file) {
                $this->friendsShareService->applyFriendsIntent($file, $grantees, $replaceExisting);
                $updated++;
            }
        }

        return $updated;
    }
}
