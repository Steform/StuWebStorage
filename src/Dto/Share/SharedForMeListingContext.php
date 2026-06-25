<?php

declare(strict_types=1);

namespace App\Dto\Share;

use App\Entity\SharedFile;

/**
 * @brief Grantee-side shared listing navigation context for one folder cursor.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class SharedForMeListingContext
{
    /**
     * @brief Build shared listing navigation context.
     * @param int $currentFolderId Effective folder cursor (0 = shared root).
     * @param array<int, SharedForMeFolderNode> $registry All folders reachable from active shared files.
     * @param list<array{id: int, name: string}> $foldersAtLevel Child folders for the current cursor.
     * @param list<array{id: int, name: string}> $breadcrumbFolders Breadcrumb from shared root to current folder.
     * @param list<SharedFile> $filesAtLevel Files directly in the current folder.
     * @param array<int, int> $folderSizeBytes Recursive byte sizes keyed by folder id.
     * @return void
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function __construct(
        public readonly int $currentFolderId,
        public readonly array $registry,
        public readonly array $foldersAtLevel,
        public readonly array $breadcrumbFolders,
        public readonly array $filesAtLevel,
        public readonly array $folderSizeBytes,
    ) {
    }
}
