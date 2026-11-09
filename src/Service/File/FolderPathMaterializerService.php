<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\Share\FolderAncestorService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Materialize nested folder paths and resolve name conflicts for uploads and ZIP extraction.
 * @author Stephane H.
 * @date 2026-06-25
 */
final class FolderPathMaterializerService
{
    public const CONFLICT_ABORT = 'abort';

    public const CONFLICT_SKIP = 'skip';

    public const CONFLICT_RENAME = 'rename';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderAncestorService $folderAncestorService,
    ) {
    }

    /**
     * @brief Split a relative file path into parent folder segments and the file name.
     * @param string $relativeFilePath Relative path such as MonProjet/src/main.js.
     * @return array{folder_path: string, file_name: string}
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function splitRelativeFilePath(string $relativeFilePath): array
    {
        $relativeFilePath = str_replace('\\', '/', trim($relativeFilePath));
        $relativeFilePath = trim($relativeFilePath, '/');
        if ($relativeFilePath === '') {
            return ['folder_path' => '', 'file_name' => 'file'];
        }

        $parts = explode('/', $relativeFilePath);
        $fileName = (string) array_pop($parts);
        if ($fileName === '') {
            return ['folder_path' => implode('/', $parts), 'file_name' => 'file'];
        }

        return ['folder_path' => implode('/', $parts), 'file_name' => $fileName];
    }

    /**
     * @brief Ensure folder segments exist under a base folder and return the deepest folder.
     * @param int $ownerUserId Owner user id.
     * @param Folder|null $baseFolder Root folder for relative paths.
     * @param string $relativePath Relative folder path (may be empty).
     * @param string $conflictPolicy One of CONFLICT_* constants.
     * @param array<string, mixed>|null $meta Optional tracking meta with created_folder_ids list.
     * @return Folder|null|string Folder, base when path empty, or abort sentinel string.
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function ensureFolderPathFromRelative(
        int $ownerUserId,
        ?Folder $baseFolder,
        string $relativePath,
        string $conflictPolicy,
        ?array &$meta = null,
    ): Folder|null|string {
        $relativePath = trim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return $baseFolder;
        }

        $segments = explode('/', $relativePath);
        $cursor = $baseFolder;
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return self::CONFLICT_ABORT;
            }
            $resolved = $this->resolveFolderSegmentName($ownerUserId, $cursor, $segment, $conflictPolicy, $meta);
            if ($resolved['action'] === 'abort') {
                return self::CONFLICT_ABORT;
            }
            if ($resolved['action'] === 'skip') {
                return $conflictPolicy === self::CONFLICT_SKIP ? $cursor : self::CONFLICT_ABORT;
            }
            $folder = $resolved['folder'];
            if ($folder === null) {
                $folder = new Folder($ownerUserId, $resolved['name'], $cursor);
                $this->entityManager->persist($folder);
                $this->entityManager->flush();
                $this->folderAncestorService->rebuildForFolder($folder);
                $fid = (int) ($folder->getId() ?? 0);
                if ($fid > 0 && $meta !== null) {
                    /** @var list<int> $createdFolderIds */
                    $createdFolderIds = $meta['created_folder_ids'] ?? [];
                    $createdFolderIds[] = $fid;
                    $meta['created_folder_ids'] = $createdFolderIds;
                }
            }
            $cursor = $folder;
        }

        return $cursor;
    }

    /**
     * @brief Resolve a folder segment under a parent, creating or reusing as needed.
     * @param int $ownerUserId Owner user id.
     * @param Folder|null $parent Parent folder.
     * @param string $segment Raw folder segment name.
     * @param string $conflictPolicy Conflict policy constant.
     * @param array<string, mixed>|null $meta Optional job meta for tracking created folders.
     * @return array{action: string, name: string, folder: Folder|null}
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function resolveFolderSegmentName(
        int $ownerUserId,
        ?Folder $parent,
        string $segment,
        string $conflictPolicy,
        ?array &$meta = null,
    ): array {
        $normalized = Folder::normalizeName($segment);
        $existingFolder = $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerUserId, $parent, $normalized);
        if ($existingFolder instanceof Folder) {
            return ['action' => 'use', 'name' => $existingFolder->getName(), 'folder' => $existingFolder];
        }

        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerUserId, $parent, $normalized, null) instanceof SharedFile) {
            return $this->resolveConflictAction($ownerUserId, $parent, $segment, $conflictPolicy, true, $meta);
        }

        return ['action' => 'create', 'name' => $segment, 'folder' => null];
    }

    /**
     * @brief Resolve a file name under a parent folder according to conflict policy.
     * @param int $ownerUserId Owner user id.
     * @param Folder|null $parent Parent folder.
     * @param string $fileName Desired file name.
     * @param string $conflictPolicy Conflict policy constant.
     * @return string|null Resolved unique file name or null when skipped/aborted.
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function resolveFileName(
        int $ownerUserId,
        ?Folder $parent,
        string $fileName,
        string $conflictPolicy,
    ): ?string {
        $normalized = Folder::normalizeName($fileName);
        $hasFileConflict = $this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerUserId, $parent, $normalized, null) instanceof SharedFile;
        $hasFolderConflict = $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerUserId, $parent, $normalized) instanceof Folder;

        if (!$hasFileConflict && !$hasFolderConflict) {
            return $fileName;
        }

        if ($conflictPolicy === self::CONFLICT_SKIP || $conflictPolicy === self::CONFLICT_ABORT) {
            return null;
        }

        return $this->buildRenamedFileName($ownerUserId, $parent, $fileName);
    }

    /**
     * @brief Whether a file name would conflict in the target parent folder.
     * @param int $ownerUserId Owner user id.
     * @param Folder|null $parent Parent folder.
     * @param string $fileName Candidate display name.
     * @return bool
     * @date 2026-06-25
     * @author Stephane H.
     */
    public function hasFileNameConflict(int $ownerUserId, ?Folder $parent, string $fileName): bool
    {
        $normalized = Folder::normalizeName($fileName);
        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerUserId, $parent, $normalized, null) instanceof SharedFile) {
            return true;
        }

        return $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerUserId, $parent, $normalized) instanceof Folder;
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param Folder|null $parent Parent folder.
     * @param string $originalName Original display name.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildRenamedFileName(int $ownerUserId, ?Folder $parent, string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        if ($base === '') {
            $base = $originalName;
        }

        for ($n = 1; $n <= 999; ++$n) {
            $candidate = $ext !== '' ? \sprintf('%s (%d).%s', $base, $n, $ext) : \sprintf('%s (%d)', $base, $n);
            if (!$this->hasFileNameConflict($ownerUserId, $parent, $candidate)) {
                return $candidate;
            }
        }

        return $originalName.'_'.bin2hex(random_bytes(4));
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param Folder|null $parent Parent folder.
     * @param string $segment Original segment name.
     * @param string $conflictPolicy Conflict policy constant.
     * @param bool $isFolder Whether the conflict target is a folder segment.
     * @param array<string, mixed>|null $meta Optional job meta.
     * @return array{action: string, name: string, folder: Folder|null}
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function resolveConflictAction(
        int $ownerUserId,
        ?Folder $parent,
        string $segment,
        string $conflictPolicy,
        bool $isFolder,
        ?array &$meta,
    ): array {
        if ($conflictPolicy === self::CONFLICT_SKIP) {
            return ['action' => 'skip', 'name' => $segment, 'folder' => null];
        }
        if ($conflictPolicy === self::CONFLICT_ABORT) {
            return ['action' => 'abort', 'name' => $segment, 'folder' => null];
        }

        $renamed = $isFolder
            ? $this->buildRenamedFolderName($ownerUserId, $parent, $segment)
            : $this->buildRenamedFileName($ownerUserId, $parent, $segment);

        return ['action' => 'create', 'name' => $renamed, 'folder' => null];
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param Folder|null $parent Parent folder.
     * @param string $originalName Original folder name.
     * @return string
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function buildRenamedFolderName(int $ownerUserId, ?Folder $parent, string $originalName): string
    {
        for ($n = 1; $n <= 999; ++$n) {
            $candidate = \sprintf('%s (%d)', $originalName, $n);
            $normalized = Folder::normalizeName($candidate);
            $fileConflict = $this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerUserId, $parent, $normalized, null) instanceof SharedFile;
            $folderConflict = $this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerUserId, $parent, $normalized) instanceof Folder;
            if (!$fileConflict && !$folderConflict) {
                return $candidate;
            }
        }

        return $originalName.'_'.bin2hex(random_bytes(4));
    }
}
