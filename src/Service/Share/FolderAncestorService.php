<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Entity\Folder;
use App\Entity\FolderAncestor;
use App\Repository\FolderAncestorRepository;
use App\Repository\FolderRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maintains folder_ancestor transitive closure rows.
 */
final class FolderAncestorService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderAncestorRepository $folderAncestorRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
    ) {
    }

    /**
     * @brief Rebuild closure rows for one folder and its entire descendant subtree.
     * @param Folder $folder Root folder whose subtree must be rebuilt.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function rebuildForFolder(Folder $folder): void
    {
        $ownerUserId = $folder->getOwnerUserId();
        $subtree = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        foreach ($subtree as $subFolder) {
            $this->rebuildSingleFolder($subFolder);
        }
    }

    /**
     * @brief Rebuild closure rows for every folder owned by one user.
     * @param int $ownerUserId Owner user identifier.
     * @return int Number of folders rebuilt.
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function rebuildForOwner(int $ownerUserId): int
    {
        $folders = $this->folderRepository->findBy(['ownerUserId' => $ownerUserId], ['id' => 'ASC']);
        $count = 0;
        foreach ($folders as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }
            $this->rebuildSingleFolder($folder);
            ++$count;
        }

        return $count;
    }

    /**
     * @brief Delete closure rows for every folder in a subtree before folder deletion.
     * @param int $rootFolderId Root folder identifier.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function deleteForFolderSubtree(int $rootFolderId): void
    {
        if ($rootFolderId < 1) {
            return;
        }
        $this->folderAncestorRepository->deleteBySubtreeRoot($rootFolderId);
    }

    /**
     * @brief Rebuild closure rows for one folder only.
     * @param Folder $folder Target folder.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function rebuildSingleFolder(Folder $folder): void
    {
        $folderId = (int) ($folder->getId() ?? 0);
        if ($folderId < 1) {
            return;
        }

        $this->folderAncestorRepository->deleteByFolderId($folderId);

        $depth = 0;
        $cursor = $folder;
        while ($cursor instanceof Folder) {
            $ancestorId = (int) ($cursor->getId() ?? 0);
            if ($ancestorId < 1) {
                break;
            }
            $this->entityManager->persist(new FolderAncestor($folderId, $ancestorId, $depth));
            $cursor = $cursor->getParent();
            ++$depth;
        }
        $this->entityManager->flush();
    }
}
