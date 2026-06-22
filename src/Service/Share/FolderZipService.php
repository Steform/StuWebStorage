<?php

namespace App\Service\Share;

use App\Entity\Folder;
use App\Repository\SharedFileRepository;
use App\Service\File\FileEncryptionService;

/**
 * Service FolderZipService.
 */
class FolderZipService
{
    public function __construct(
        private readonly FolderTreeService $folderTreeService,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FileEncryptionService $fileEncryptionService,
    ) {
    }

    /**
     * @brief Build a temporary ZIP archive for a folder subtree.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder $folder Root folder.
     * @return array{zip_path: string, zip_name: string, file_count: int}
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function buildFolderZip(int $ownerUserId, Folder $folder): array
    {
        $zip = new \ZipArchive();
        $zipName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $folder->getName()) ?: 'folder';
        $zipName .= '_'.(new \DateTimeImmutable())->format('Ymd-Hi').'.zip';
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage_'.$zipName;
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $count = 0;
        $folders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $folder);
        foreach ($folders as $subFolder) {
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerUserId, 'folder' => $subFolder]);
            $relativePrefix = $this->buildRelativeFolderPath($folder, $subFolder);
            foreach ($files as $file) {
                $plain = $this->fileEncryptionService->decryptFromStorage($file->getStoragePath());
                $rawEntry = ltrim($relativePrefix.'/'.$file->getOriginalFileName(), '/');
                $fid = (int) ($file->getId() ?? 0);
                $entryName = ZipEntryNameSanitizer::sanitizeEntryPath($rawEntry, $fid > 0 ? $fid : 0);
                $zip->addFromString($entryName, $plain);
                $count++;
            }
        }
        $zip->close();

        return [
            'zip_path' => $zipPath,
            'zip_name' => $zipName,
            'file_count' => $count,
        ];
    }

    /**
     * @brief Build a temporary ZIP archive from a pre-filtered shared-file set.
     * @param Folder $folder Root folder.
     * @param array<int, \App\Entity\SharedFile> $files Files already authorized.
     * @return array{zip_path: string, zip_name: string, file_count: int}
     * @date 2026-04-30
     * @author Stephane H.
     */
    public function buildFolderZipFromFiles(Folder $folder, array $files): array
    {
        $zip = new \ZipArchive();
        $zipName = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $folder->getName()) ?: 'folder';
        $zipName .= '_'.(new \DateTimeImmutable())->format('Ymd-Hi').'.zip';
        $zipPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'storage_'.$zipName;
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $count = 0;
        foreach ($files as $file) {
            $plain = $this->fileEncryptionService->decryptFromStorage($file->getStoragePath());
            $fileFolder = $file->getFolder();
            $relativePrefix = $fileFolder instanceof Folder ? $this->buildRelativeFolderPath($folder, $fileFolder) : $folder->getName();
            $rawEntry = ltrim($relativePrefix.'/'.$file->getOriginalFileName(), '/');
            $fid = (int) ($file->getId() ?? 0);
            $entryName = ZipEntryNameSanitizer::sanitizeEntryPath($rawEntry, $fid > 0 ? $fid : 0);
            $zip->addFromString($entryName, $plain);
            $count++;
        }
        $zip->close();

        return [
            'zip_path' => $zipPath,
            'zip_name' => $zipName,
            'file_count' => $count,
        ];
    }

    /**
     * @brief Build relative folder path from one selected root to one descendant folder.
     * @param Folder $root Selected root folder.
     * @param Folder $child Descendant folder.
     * @return string
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function buildRelativeFolderPathFromRoot(Folder $root, Folder $child): string
    {
        return $this->buildRelativeFolderPath($root, $child);
    }

    /**
     * @brief Build relative path from root folder to one descendant.
     * @param Folder $root Root folder.
     * @param Folder $child Descendant folder.
     * @return string
     * @date 2026-04-29
     * @author Stephane H.
     */
    private function buildRelativeFolderPath(Folder $root, Folder $child): string
    {
        if ($root->getId() === $child->getId()) {
            return $root->getName();
        }
        $parts = [$child->getName()];
        $cursor = $child->getParent();
        while ($cursor instanceof Folder && $cursor->getId() !== $root->getId()) {
            array_unshift($parts, $cursor->getName());
            $cursor = $cursor->getParent();
        }
        array_unshift($parts, $root->getName());

        return implode('/', $parts);
    }
}
