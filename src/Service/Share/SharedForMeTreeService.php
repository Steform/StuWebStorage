<?php

declare(strict_types=1);

namespace App\Service\Share;

use App\Dto\Share\SharedForMeFolderNode;
use App\Dto\Share\SharedForMeListingContext;
use App\Entity\Folder;
use App\Entity\FolderShareGrant;
use App\Entity\SharedFile;
use App\Repository\FolderRepository;
use App\Repository\FolderShareGrantRepository;

/**
 * @brief Build grantee-side shared folder tree navigation from active shared files and folder grants.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class SharedForMeTreeService
{
    public function __construct(
        private readonly FolderShareGrantRepository $folderShareGrantRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
    ) {
    }

    /**
     * @brief Build listing navigation context for the shared-for-me section.
     * @param list<SharedFile> $activeSharedFiles Active shared files visible to grantee.
     * @param int $currentFolderId Requested folder cursor (0 = shared root).
     * @param int $granteeUserId Grantee user identifier for folder-level grants.
     * @return SharedForMeListingContext
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function buildListingContext(array $activeSharedFiles, int $currentFolderId, int $granteeUserId = 0): SharedForMeListingContext
    {
        $registry = $this->buildRegistry($activeSharedFiles, $granteeUserId);
        if ($currentFolderId > 0 && !isset($registry[$currentFolderId])) {
            $currentFolderId = 0;
        }

        $foldersAtLevel = $this->listFoldersAtLevel($registry, $currentFolderId);
        $breadcrumbFolders = $this->buildBreadcrumb($registry, $currentFolderId);
        $filesAtLevel = $this->listFilesAtLevel($activeSharedFiles, $currentFolderId);
        $folderSizeBytes = $this->computeRecursiveFolderSizes($registry, $activeSharedFiles);

        return new SharedForMeListingContext(
            $currentFolderId,
            $registry,
            $foldersAtLevel,
            $breadcrumbFolders,
            $filesAtLevel,
            $folderSizeBytes,
        );
    }

    /**
     * @brief Collect folder lineage from shared files and active folder grants.
     * @param list<SharedFile> $activeSharedFiles Active shared files.
     * @param int $granteeUserId Grantee user identifier.
     * @return array<int, SharedForMeFolderNode>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildRegistry(array $activeSharedFiles, int $granteeUserId): array
    {
        $registry = [];
        foreach ($activeSharedFiles as $sharedForMeFile) {
            $this->registerFolderLineage($registry, $sharedForMeFile->getFolder());
        }

        if ($granteeUserId > 0) {
            $folderGrants = $this->folderShareGrantRepository->findActiveByGrantee($granteeUserId);
            foreach ($folderGrants as $folderGrant) {
                if (!$folderGrant instanceof FolderShareGrant) {
                    continue;
                }
                $sharedRoot = $this->folderRepository->find($folderGrant->getFolderId());
                if (!$sharedRoot instanceof Folder) {
                    continue;
                }
                $subtree = $this->folderTreeService->collectSubtreeFolders($sharedRoot->getOwnerUserId(), $sharedRoot);
                foreach ($subtree as $subFolder) {
                    $this->registerFolderLineage($registry, $subFolder);
                }
            }
        }

        return $registry;
    }

    /**
     * @brief Register one folder and its ancestors in the shared registry.
     * @param array<int, SharedForMeFolderNode> $registry Mutable registry.
     * @param Folder|null $folder Folder cursor.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    private function registerFolderLineage(array &$registry, ?Folder $folder): void
    {
        $folderCursor = $folder;
        while ($folderCursor instanceof Folder) {
            $folderId = (int) ($folderCursor->getId() ?? 0);
            if ($folderId > 0) {
                $parentFolder = $folderCursor->getParent();
                $parentId = $parentFolder?->getId();
                $registry[$folderId] = new SharedForMeFolderNode(
                    $folderId,
                    $folderCursor->getName(),
                    $parentId !== null ? (int) $parentId : null,
                    (int) $folderCursor->getOwnerUserId(),
                );
            }
            $folderCursor = $folderCursor->getParent();
        }
    }

    /**
     * @brief List folders visible at the current navigation level.
     * @param array<int, SharedForMeFolderNode> $registry Shared folder registry.
     * @param int $currentFolderId Current folder cursor.
     * @return list<array{id: int, name: string}>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function listFoldersAtLevel(array $registry, int $currentFolderId): array
    {
        $foldersAtLevel = [];
        foreach ($registry as $folderNode) {
            $parentId = $folderNode->parentId;
            $isAtLevel = $currentFolderId > 0
                ? $parentId === $currentFolderId
                : ($parentId === null || !isset($registry[$parentId]));
            if (!$isAtLevel) {
                continue;
            }
            $foldersAtLevel[$folderNode->id] = $folderNode->toListingRow();
        }
        ksort($foldersAtLevel);

        return array_values($foldersAtLevel);
    }

    /**
     * @brief Build breadcrumb chain from shared root to current folder.
     * @param array<int, SharedForMeFolderNode> $registry Shared folder registry.
     * @param int $currentFolderId Current folder cursor.
     * @return list<array{id: int, name: string}>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildBreadcrumb(array $registry, int $currentFolderId): array
    {
        if ($currentFolderId < 1 || !isset($registry[$currentFolderId])) {
            return [];
        }

        $stack = [];
        $cursorId = $currentFolderId;
        while (isset($registry[$cursorId])) {
            $node = $registry[$cursorId];
            array_unshift($stack, $node->toListingRow());
            $parentId = $node->parentId;
            if ($parentId === null || !isset($registry[$parentId])) {
                break;
            }
            $cursorId = $parentId;
        }

        return $stack;
    }

    /**
     * @brief List files directly contained in the current folder cursor.
     * @param list<SharedFile> $activeSharedFiles Active shared files.
     * @param int $currentFolderId Current folder cursor.
     * @return list<SharedFile>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function listFilesAtLevel(array $activeSharedFiles, int $currentFolderId): array
    {
        return array_values(array_filter(
            $activeSharedFiles,
            static function (SharedFile $sharedFile) use ($currentFolderId): bool {
                $folderId = $sharedFile->getFolder()?->getId();
                if ($currentFolderId > 0) {
                    return $folderId === $currentFolderId;
                }

                return $folderId === null;
            }
        ));
    }

    /**
     * @brief Compute recursive byte sizes for every folder in the shared registry.
     * @param array<int, SharedForMeFolderNode> $registry Shared folder registry.
     * @param list<SharedFile> $activeSharedFiles Active shared files.
     * @return array<int, int>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function computeRecursiveFolderSizes(array $registry, array $activeSharedFiles): array
    {
        $childrenByParent = [];
        foreach ($registry as $folderNode) {
            $parentKey = $folderNode->parentId ?? 0;
            $childrenByParent[$parentKey][] = $folderNode->id;
        }

        $folderSizeBytes = [];
        foreach ($registry as $folderId => $folderNode) {
            $subtreeFolderIds = $this->collectRegistrySubtreeFolderIds($folderId, $childrenByParent);
            $totalBytes = 0;
            foreach ($activeSharedFiles as $sharedForMeFile) {
                $fileFolderId = (int) ($sharedForMeFile->getFolder()?->getId() ?? 0);
                if ($fileFolderId > 0 && in_array($fileFolderId, $subtreeFolderIds, true)) {
                    $totalBytes += (int) $sharedForMeFile->getByteSize();
                }
            }
            $folderSizeBytes[$folderId] = $totalBytes;
            unset($folderNode);
        }

        return $folderSizeBytes;
    }

    /**
     * @brief Collect folder ids in a registry subtree rooted at the given folder.
     * @param int $rootFolderId Root folder identifier.
     * @param array<int, list<int>> $childrenByParent Children grouped by parent id (0 = owner root).
     * @return list<int>
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function collectRegistrySubtreeFolderIds(int $rootFolderId, array $childrenByParent): array
    {
        $all = [$rootFolderId];
        $queue = [$rootFolderId];
        while ($queue !== []) {
            $currentId = array_shift($queue);
            if ($currentId === null) {
                continue;
            }
            foreach ($childrenByParent[$currentId] ?? [] as $childId) {
                $all[] = $childId;
                $queue[] = $childId;
            }
        }

        return $all;
    }
}
