<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Repository\SharedFileRepository;

/**
 * Service PublicFolderZipService.
 */
final class PublicFolderZipService
{
    public function __construct(
        private readonly FolderTreeService $folderTreeService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderZipService $folderZipService,
        private readonly int $maxTotalBytes,
        private readonly int $maxFileCount,
        private readonly int $maxBuildSeconds,
    ) {
    }

    /**
     * @brief Build a temporary ZIP for public folder download including only shared files with active public channel in the subtree.
     * @param int $ownerUserId Folder owner user identifier.
     * @param Folder $folder Root folder for subtree traversal.
     * @return array{zip_path: string, zip_name: string, file_count: int}
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function buildPublicSubtreeZip(int $ownerUserId, Folder $folder): array
    {
        $startedAt = microtime(true);
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        /** @var array<int, SharedFile> $eligible */
        $eligible = [];
        $totalBytes = 0;

        foreach ($folders as $subFolder) {
            if (microtime(true) - $startedAt > $this->maxBuildSeconds) {
                throw new \RuntimeException('download.public_folder.zip_limit_time');
            }

            $rows = $this->sharedFileRepository->findBy([
                'ownerUserId' => $ownerUserId,
                'folder' => $subFolder,
            ]);

            foreach ($rows as $file) {
                if (!$file instanceof SharedFile || !$file->isPublicShareActive()) {
                    continue;
                }

                if (\count($eligible) >= $this->maxFileCount) {
                    throw new \RuntimeException('download.public_folder.zip_limit_files');
                }

                $sz = (int) $file->getByteSize();
                if ($totalBytes + $sz > $this->maxTotalBytes) {
                    throw new \RuntimeException('download.public_folder.zip_limit_bytes');
                }

                $eligible[] = $file;
                $totalBytes += $sz;
            }
        }

        if ($eligible === []) {
            throw new \RuntimeException('download.public_folder.zip_empty');
        }

        if (microtime(true) - $startedAt > $this->maxBuildSeconds) {
            throw new \RuntimeException('download.public_folder.zip_limit_time');
        }

        return $this->folderZipService->buildFolderZipFromFiles($folder, $eligible);
    }
}
