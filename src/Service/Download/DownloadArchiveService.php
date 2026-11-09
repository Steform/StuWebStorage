<?php

declare(strict_types=1);

namespace App\Service\Download;

use App\Entity\DownloadArchive;
use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\DownloadArchiveRepository;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\File\FileEncryptionService;
use App\Service\Share\FolderTreeService;
use App\Service\Share\FolderZipService;
use App\Service\Share\ZipEntryNameSanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Create, prepare, and validate virtual ZIP archives.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class DownloadArchiveService
{
    /**
     * @brief Construct archive service.
     * @param DownloadArchiveRepository $archiveRepository Archive repository.
     * @param SharedFileRepository $sharedFileRepository File repository.
     * @param FolderRepository $folderRepository Folder repository.
     * @param FolderTreeService $folderTreeService Folder tree helpers.
     * @param FolderZipService $folderZipService Relative path helpers.
     * @param FileEncryptionService $fileEncryptionService Fingerprint computation.
     * @param EntityManagerInterface $entityManager ORM.
     * @param int $archiveTtlSeconds Archive TTL.
     * @param int $maxTotalBytes Max archive payload (0 = unlimited).
     * @param int $maxEntries Max entry count.
     * @param int $tickSeconds Preparation tick budget.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly DownloadArchiveRepository $archiveRepository,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly FolderZipService $folderZipService,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $archiveTtlSeconds = 604800,
        private readonly int $maxTotalBytes = 0,
        private readonly int $maxEntries = 10000,
        private readonly int $tickSeconds = 20,
    ) {
    }

    /**
     * @brief Create an archive from selected file/folder ids for an authenticated actor.
     * @param User $actor Authenticated user.
     * @param callable(User, SharedFile): bool $canDownload ACL predicate.
     * @param list<int> $fileIds Selected file ids.
     * @param list<int> $folderIds Selected owned folder ids.
     * @param int $effectiveOwnerId Owner scope for folder expansion.
     * @param string $kind Archive kind.
     * @return DownloadArchive
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function createFromSelection(
        User $actor,
        callable $canDownload,
        array $fileIds,
        array $folderIds,
        int $effectiveOwnerId,
        string $kind = DownloadArchive::KIND_SELECTION,
    ): DownloadArchive {
        $selectedFolderRoots = [];
        $candidateFileContexts = [];
        foreach ($fileIds as $id) {
            if ($id > 0) {
                $candidateFileContexts[$id] = ['fileId' => $id, 'selectedFolderId' => null];
            }
        }

        foreach ($folderIds as $folderId) {
            if ($folderId < 1) {
                continue;
            }
            $folder = $this->folderRepository->find($folderId);
            if (!$folder instanceof Folder || (int) $folder->getOwnerUserId() !== $effectiveOwnerId) {
                continue;
            }
            $selectedFolderRoots[(int) $folder->getId()] = $folder;
            foreach ($this->folderTreeService->collectSubtreeFolders($effectiveOwnerId, $folder) as $subFolder) {
                $rows = $this->sharedFileRepository->findBy([
                    'ownerUserId' => $effectiveOwnerId,
                    'folder' => $subFolder,
                ]);
                foreach ($rows as $row) {
                    if (!$row instanceof SharedFile || $row->getId() === null) {
                        continue;
                    }
                    $fileId = (int) $row->getId();
                    if (!isset($candidateFileContexts[$fileId])) {
                        $candidateFileContexts[$fileId] = [
                            'fileId' => $fileId,
                            'selectedFolderId' => (int) $folder->getId(),
                        ];
                    } elseif ($candidateFileContexts[$fileId]['selectedFolderId'] === null) {
                        $candidateFileContexts[$fileId]['selectedFolderId'] = (int) $folder->getId();
                    }
                }
            }
        }

        $usedEntryNames = [];
        $entries = [];
        $skipped = 0;
        $payloadBytes = 0;

        foreach (array_values($candidateFileContexts) as $fileContext) {
            $id = (int) ($fileContext['fileId'] ?? 0);
            $sharedFile = $this->sharedFileRepository->find($id);
            if (!$sharedFile instanceof SharedFile) {
                ++$skipped;
                continue;
            }
            if (!$canDownload($actor, $sharedFile)) {
                ++$skipped;
                continue;
            }

            $rawEntryName = (string) $sharedFile->getOriginalFileName();
            $selectedFolderId = (int) ($fileContext['selectedFolderId'] ?? 0);
            if ($selectedFolderId > 0 && isset($selectedFolderRoots[$selectedFolderId])) {
                $selectedRoot = $selectedFolderRoots[$selectedFolderId];
                $fileFolder = $sharedFile->getFolder();
                if ($fileFolder instanceof Folder) {
                    $relativePrefix = $this->folderZipService->buildRelativeFolderPathFromRoot($selectedRoot, $fileFolder);
                    $rawEntryName = ltrim($relativePrefix.'/'.$sharedFile->getOriginalFileName(), '/');
                }
            }
            $entryName = $this->buildUniqueZipEntryName($rawEntryName, $id, $usedEntryNames);
            $size = $sharedFile->getByteSize();
            $payloadBytes += $size;
            $entries[] = [
                'fileId' => $id,
                'entryName' => $entryName,
                'size' => $size,
                'mtime' => $sharedFile->getUpdatedAt()->getTimestamp(),
                'crc32' => $sharedFile->getContentCrc32(),
            ];
        }

        return $this->persistArchive(
            $entries,
            $skipped,
            $payloadBytes,
            DownloadGrantService::ACTOR_USER,
            (string) $actor->getId(),
            $effectiveOwnerId,
            $kind,
            'files-selection-'.(new \DateTimeImmutable())->format('Ymd-Hi').'.zip',
        );
    }

    /**
     * @brief Create archive from an authorized file list under one folder root.
     * @param User $actor Actor.
     * @param Folder $folder Root folder.
     * @param list<SharedFile> $files Authorized files.
     * @param string $kind Archive kind.
     * @return DownloadArchive
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function createFromFiles(User $actor, Folder $folder, array $files, string $kind = DownloadArchive::KIND_SHARED_FOLDER): DownloadArchive
    {
        $usedEntryNames = [];
        $entries = [];
        $payloadBytes = 0;
        foreach ($files as $sharedFile) {
            if (!$sharedFile instanceof SharedFile || $sharedFile->getId() === null) {
                continue;
            }
            $id = (int) $sharedFile->getId();
            $fileFolder = $sharedFile->getFolder();
            $relativePrefix = $fileFolder instanceof Folder
                ? $this->folderZipService->buildRelativeFolderPathFromRoot($folder, $fileFolder)
                : $folder->getName();
            $rawEntry = ltrim($relativePrefix.'/'.$sharedFile->getOriginalFileName(), '/');
            $entryName = $this->buildUniqueZipEntryName($rawEntry, $id, $usedEntryNames);
            $size = $sharedFile->getByteSize();
            $payloadBytes += $size;
            $entries[] = [
                'fileId' => $id,
                'entryName' => $entryName,
                'size' => $size,
                'mtime' => $sharedFile->getUpdatedAt()->getTimestamp(),
                'crc32' => $sharedFile->getContentCrc32(),
            ];
        }

        $zipLabel = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $folder->getName()) ?: 'folder';
        $zipLabel .= '_'.(new \DateTimeImmutable())->format('Ymd-Hi').'.zip';

        return $this->persistArchive(
            $entries,
            0,
            $payloadBytes,
            DownloadGrantService::ACTOR_USER,
            (string) $actor->getId(),
            (int) $folder->getOwnerUserId(),
            $kind,
            $zipLabel,
        );
    }

    /**
     * @brief Advance fingerprint preparation for one archive within the tick budget.
     * @param DownloadArchive $archive Archive entity.
     * @return array{status: string, preparedBytes: int, totalBytes: int, errorCode: ?string}
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function tickPrepare(DownloadArchive $archive): array
    {
        if ($archive->isReady()) {
            return $this->progressPayload($archive);
        }
        if ($archive->getStatus() === DownloadArchive::STATUS_FAILED) {
            return $this->progressPayload($archive);
        }

        $now = new \DateTimeImmutable();
        $lockUntil = $archive->getPreparingUntil();
        if ($lockUntil instanceof \DateTimeImmutable && $lockUntil > $now) {
            return $this->progressPayload($archive);
        }

        $archive->setPreparingUntil($now->modify('+'.max(5, $this->tickSeconds + 5).' seconds'));
        $this->archiveRepository->save($archive);

        $deadline = microtime(true) + max(1, $this->tickSeconds);
        $entries = $archive->getEntriesJson();
        $preparedBytes = 0;
        $changed = false;

        try {
            foreach ($entries as $index => $entry) {
                $size = (int) ($entry['size'] ?? 0);
                if (isset($entry['crc32']) && $entry['crc32'] !== null) {
                    $preparedBytes += $size;
                    continue;
                }
                if (microtime(true) >= $deadline) {
                    break;
                }
                $fileId = (int) ($entry['fileId'] ?? 0);
                $sharedFile = $this->sharedFileRepository->find($fileId);
                if (!$sharedFile instanceof SharedFile) {
                    throw new \RuntimeException('download.archive_entry_missing');
                }
                if ($sharedFile->getContentCrc32() !== null && $sharedFile->getContentSha256() !== null) {
                    $entries[$index]['crc32'] = $sharedFile->getContentCrc32();
                    $preparedBytes += $size;
                    $changed = true;
                    continue;
                }

                $fingerprints = $this->fileEncryptionService->computeFingerprintsFromStorage($sharedFile->getStoragePath());
                $sharedFile->setContentCrc32($fingerprints['content_crc32']);
                $sharedFile->setContentSha256($fingerprints['content_sha256']);
                $entries[$index]['crc32'] = $fingerprints['content_crc32'];
                $preparedBytes += $size;
                $changed = true;
                $this->entityManager->flush();
            }
        } catch (\RuntimeException $e) {
            $archive->setStatus(DownloadArchive::STATUS_FAILED);
            $archive->setErrorCode($e->getMessage());
            $archive->setPreparingUntil(null);
            $this->archiveRepository->save($archive);

            return $this->progressPayload($archive);
        }

        if ($changed) {
            $archive->setEntriesJson($entries);
        }
        $archive->setPreparedBytes($preparedBytes);

        $allReady = true;
        foreach ($entries as $entry) {
            if (!isset($entry['crc32']) || $entry['crc32'] === null) {
                $allReady = false;
                break;
            }
        }

        if ($allReady) {
            $this->markReady($archive, $entries);
        } else {
            $archive->setPreparingUntil(null);
            $this->archiveRepository->save($archive);
        }

        return $this->progressPayload($archive);
    }

    /**
     * @brief Public progress/status array for JSON APIs.
     * @param DownloadArchive $archive Archive.
     * @return array{token: string, status: string, totalBytes: int, preparedBytes: int, entryCount: int, skippedCount: int, fileName: string, errorCode: ?string, expiresAt: string}
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function toPublicArray(DownloadArchive $archive): array
    {
        return [
            'token' => $archive->getToken(),
            'status' => $archive->getStatus(),
            'totalBytes' => $archive->getTotalBytes(),
            'preparedBytes' => $archive->getPreparedBytes(),
            'entryCount' => $archive->getEntryCount(),
            'skippedCount' => $archive->getSkippedCount(),
            'fileName' => $archive->getFileName(),
            'errorCode' => $archive->getErrorCode(),
            'expiresAt' => $archive->getExpiresAt()->format(\DATE_ATOM),
        ];
    }

    /**
     * @param list<array{fileId: int, entryName: string, size: int, mtime: int, crc32: int|null}> $entries
     * @param int $skipped Skipped count.
     * @param int $payloadBytes Sum of entry sizes.
     * @param string $actorType Actor type.
     * @param string $actorId Actor id.
     * @param int|null $ownerUserId Subject owner.
     * @param string $kind Kind.
     * @param string $fileName ZIP name.
     * @return DownloadArchive
     */
    private function persistArchive(
        array $entries,
        int $skipped,
        int $payloadBytes,
        string $actorType,
        string $actorId,
        ?int $ownerUserId,
        string $kind,
        string $fileName,
    ): DownloadArchive {
        if ($entries === []) {
            throw new \RuntimeException('download.archive_empty');
        }
        if (count($entries) > $this->maxEntries) {
            throw new \RuntimeException('download.archive_too_many_entries');
        }
        if ($this->maxTotalBytes > 0 && $payloadBytes > $this->maxTotalBytes) {
            throw new \RuntimeException('download.archive_too_large');
        }

        $layoutEntries = [];
        $preparedBytes = 0;
        $allReady = true;
        foreach ($entries as $entry) {
            if ($entry['crc32'] === null) {
                $allReady = false;
            } else {
                $preparedBytes += $entry['size'];
            }
            $layoutEntries[] = [
                'name' => $entry['entryName'],
                'size' => $entry['size'],
                'mtime' => $entry['mtime'],
                'crc32' => (int) ($entry['crc32'] ?? 0),
            ];
        }

        // Layout size uses crc placeholders; recompute when ready.
        $layout = VirtualZipLayout::fromEntries(array_map(
            static fn (array $e): array => [
                'name' => $e['entryName'],
                'size' => $e['size'],
                'mtime' => $e['mtime'],
                'crc32' => (int) ($e['crc32'] ?? 0),
            ],
            $entries,
        ));

        $expires = (new \DateTimeImmutable())->modify('+'.max(3600, $this->archiveTtlSeconds).' seconds');
        $archive = new DownloadArchive(
            bin2hex(random_bytes(16)),
            $kind,
            $actorType,
            $actorId,
            $fileName,
            $expires,
        );
        $archive->setOwnerUserId($ownerUserId);
        $archive->setEntriesJson($entries);
        $archive->setSkippedCount($skipped);
        $archive->setTotalBytes($layout->getTotalSize());
        $archive->setPreparedBytes($preparedBytes);

        if ($allReady) {
            $this->markReady($archive, $entries, false);
        } else {
            $archive->setStatus(DownloadArchive::STATUS_PREPARING);
        }

        $this->archiveRepository->save($archive);

        return $archive;
    }

    /**
     * @param DownloadArchive $archive Archive.
     * @param list<array<string, mixed>> $entries Entries with crc32 set.
     * @param bool $persist Whether to flush.
     * @return void
     */
    private function markReady(DownloadArchive $archive, array $entries, bool $persist = true): void
    {
        $layout = VirtualZipLayout::fromEntries(array_map(
            static fn (array $e): array => [
                'name' => (string) $e['entryName'],
                'size' => (int) $e['size'],
                'mtime' => (int) $e['mtime'],
                'crc32' => (int) $e['crc32'],
            ],
            $entries,
        ));
        $archive->setEntriesJson($entries);
        $archive->setTotalBytes($layout->getTotalSize());
        $archive->setPreparedBytes($layout->getTotalSize());
        $archive->setStatus(DownloadArchive::STATUS_READY);
        $archive->setReadyAt(new \DateTimeImmutable());
        $archive->setPreparingUntil(null);
        $hash = hash('sha256', $archive->getToken().'|'.$layout->getTotalSize().'|'.json_encode($entries));
        $archive->setEtag('"a-'.substr($hash, 0, 40).'"');
        if ($persist) {
            $this->archiveRepository->save($archive);
        }
    }

    /**
     * @param DownloadArchive $archive Archive.
     * @return array{status: string, preparedBytes: int, totalBytes: int, errorCode: ?string}
     */
    private function progressPayload(DownloadArchive $archive): array
    {
        return [
            'status' => $archive->getStatus(),
            'preparedBytes' => $archive->getPreparedBytes(),
            'totalBytes' => $archive->getTotalBytes(),
            'errorCode' => $archive->getErrorCode(),
        ];
    }

    /**
     * @brief Build unique sanitized zip entry path.
     * @param string $rawEntryPath Raw path.
     * @param int $fallbackFileId Fallback id.
     * @param array<string, bool> $usedEntryNames Used names.
     * @return string
     * @date 2026-11-02
     * @author Stephane H.
     */
    private function buildUniqueZipEntryName(string $rawEntryPath, int $fallbackFileId, array &$usedEntryNames): string
    {
        $baseEntryName = ZipEntryNameSanitizer::sanitizeEntryPath($rawEntryPath, $fallbackFileId);
        $candidateEntryName = $baseEntryName;
        $suffix = 1;
        while (isset($usedEntryNames[$candidateEntryName])) {
            ++$suffix;
            $lastSlash = strrpos($baseEntryName, '/');
            $directoryPrefix = $lastSlash === false ? '' : substr($baseEntryName, 0, $lastSlash + 1);
            $basename = $lastSlash === false ? $baseEntryName : substr($baseEntryName, $lastSlash + 1);
            $dot = strrpos($basename, '.');
            if ($dot === false) {
                $withSuffix = $directoryPrefix.$basename.'_'.$suffix;
            } else {
                $withSuffix = $directoryPrefix.substr($basename, 0, $dot).'_'.$suffix.substr($basename, $dot);
            }
            $candidateEntryName = ZipEntryNameSanitizer::sanitizeEntryPath($withSuffix, $fallbackFileId);
        }
        $usedEntryNames[$candidateEntryName] = true;

        return $candidateEntryName;
    }
}
