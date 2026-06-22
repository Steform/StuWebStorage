<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;

/**
 * Service FolderTreeService.
 */
class FolderTreeService
{
    public function __construct(
        private readonly FolderRepository $folderRepository,
        private readonly SharedFileRepository $sharedFileRepository,
    ) {
    }

    /**
     * @brief Resolve current folder from query token with ownership enforcement.
     * @param int $ownerUserId Owner user identifier.
     * @param int|null $folderId Requested folder identifier.
     * @return Folder|null
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function resolveCurrentFolder(int $ownerUserId, ?int $folderId): ?Folder
    {
        if ($folderId === null || $folderId <= 0) {
            return null;
        }
        $folder = $this->folderRepository->find($folderId);
        if (!$folder instanceof Folder || $folder->getOwnerUserId() !== $ownerUserId) {
            return null;
        }

        return $folder;
    }

    /**
     * @brief Build breadcrumb chain from root to current folder.
     * @param Folder|null $currentFolder Current folder.
     * @return array<int, Folder>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function buildBreadcrumb(?Folder $currentFolder): array
    {
        if (!$currentFolder instanceof Folder) {
            return [];
        }
        $stack = [];
        $cursor = $currentFolder;
        while ($cursor instanceof Folder) {
            array_unshift($stack, $cursor);
            $cursor = $cursor->getParent();
        }

        return $stack;
    }

    /**
     * @brief List child folders in current owner folder.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder|null $currentFolder Current folder.
     * @return array<int, Folder>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function listCurrentChildFolders(int $ownerUserId, ?Folder $currentFolder): array
    {
        return $this->folderRepository->findChildrenForOwner($ownerUserId, $currentFolder);
    }

    /**
     * @brief Recursively collect all descendant folders including the root folder.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $rootFolder Root folder.
     * @return array<int, Folder>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function collectSubtreeFolders(int $ownerUserId, Folder $rootFolder): array
    {
        $all = [$rootFolder];
        $queue = [$rootFolder];
        while ($queue !== []) {
            $current = array_shift($queue);
            if (!$current instanceof Folder) {
                continue;
            }
            $children = $this->folderRepository->findChildrenForOwner($ownerUserId, $current);
            foreach ($children as $child) {
                $all[] = $child;
                $queue[] = $child;
            }
        }

        return $all;
    }
}
