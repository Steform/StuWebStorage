<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Dto\File\ZipExtractLimits;
use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Entity\ShareGrant;
use App\Repository\FolderRepository;
use App\Repository\PublicDownloadChallengeRepository;
use App\Repository\ShareGrantRepository;
use App\Repository\SharedFileRepository;
use App\Service\Share\FolderTreeService;
use App\Service\Share\FriendsShareService;
use App\Service\Share\PublicShareService;
use App\Service\Share\ZipEntryNameSanitizer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Incremental ZIP extraction jobs for owned encrypted storage files.
 * @author Stephane H.
 * @date 2026-06-24
 */
final class ZipExtractService
{
    public const MODE_HERE = 'here';

    public const MODE_SUBFOLDER = 'subfolder';

    public const CONFLICT_ABORT = 'abort';

    public const CONFLICT_SKIP = 'skip';

    public const CONFLICT_RENAME = 'rename';

    private const META_SUFFIX = '.json';

    private const TEMP_ZIP_SUFFIX = '.zip';

    private const SESSION_TTL_SECONDS = 86400;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly PublicDownloadChallengeRepository $publicDownloadChallengeRepository,
        private readonly PublicShareService $publicShareService,
        private readonly FriendsShareService $friendsShareService,
        private readonly FolderPathMaterializerService $folderPathMaterializerService,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @brief Build preflight payload for the extraction modal (no decrypt required).
     * @param int $ownerUserId Effective owner namespace.
     * @param SharedFile $sourceFile Owned ZIP shared file row.
     * @param ZipExtractLimits $limits Effective limits for the current actor.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function buildPreflight(int $ownerUserId, SharedFile $sourceFile, ZipExtractLimits $limits): array
    {
        if ($sourceFile->getOwnerUserId() !== $ownerUserId) {
            throw new \RuntimeException('zip_extract.forbidden');
        }
        if (strtolower($sourceFile->getFileExtension()) !== 'zip') {
            throw new \RuntimeException('zip_extract.not_zip');
        }

        return [
            'zip_file_name' => $sourceFile->getOriginalFileName(),
            'zip_file_bytes' => (int) $sourceFile->getByteSize(),
            'max_uncompressed_bytes' => $limits->maxTotalBytes,
            'max_file_count' => $limits->maxFileCount,
            'max_job_seconds' => $limits->maxSeconds,
            'limits_tier' => $limits->tier,
        ];
    }

    /**
     * @brief Create an extraction job session (decrypt and scan deferred to the first ticks).
     * @param int $ownerUserId Effective owner namespace.
     * @param SharedFile $sourceFile Owned ZIP shared file row.
     * @param string $mode One of MODE_HERE or MODE_SUBFOLDER.
     * @param string $conflictPolicy One of CONFLICT_* constants.
     * @param bool $deleteZipAfter When true, remove source ZIP after successful extraction.
     * @param ZipExtractLimits $limits Effective limits frozen for this job.
     * @return array{job_id: string, total_entries: int, total_bytes: int, phase: string}
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function createJob(
        int $ownerUserId,
        SharedFile $sourceFile,
        string $mode,
        string $conflictPolicy,
        bool $deleteZipAfter,
        ZipExtractLimits $limits,
    ): array {
        $this->purgeExpiredForOwner($ownerUserId);
        $this->assertValidMode($mode);
        $this->assertValidConflictPolicy($conflictPolicy);

        if ($sourceFile->getOwnerUserId() !== $ownerUserId) {
            throw new \RuntimeException('zip_extract.forbidden');
        }
        if (strtolower($sourceFile->getFileExtension()) !== 'zip') {
            throw new \RuntimeException('zip_extract.not_zip');
        }

        $jobId = bin2hex(random_bytes(16));
        $baseDir = $this->sessionBaseDir($ownerUserId);
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('zip_extract.storage_failed');
        }

        $targetFolder = $sourceFile->getFolder();
        $startedAt = microtime(true);

        $meta = [
            'owner_user_id' => $ownerUserId,
            'source_file_id' => (int) $sourceFile->getId(),
            'source_storage_path' => $sourceFile->getStoragePath(),
            'mode' => $mode,
            'conflict_policy' => $conflictPolicy,
            'delete_zip_after' => $deleteZipAfter,
            'target_folder_id' => $targetFolder instanceof Folder ? (int) ($targetFolder->getId() ?? 0) : 0,
            'extract_root_folder_id' => 0,
            'created_at' => time(),
            'started_at' => $startedAt,
            'phase' => 'pending',
            'next_index' => 0,
            'entries' => [],
            'total_entries' => 0,
            'total_bytes' => 0,
            'extracted' => 0,
            'skipped' => 0,
            'created_file_ids' => [],
            'created_folder_ids' => [],
            'current_entry' => '',
            'error_message' => '',
            'limits' => $limits->toMetaArray(),
        ];
        $this->saveMeta($ownerUserId, $jobId, $meta);

        return [
            'job_id' => $jobId,
            'total_entries' => 0,
            'total_bytes' => 0,
            'phase' => 'pending',
        ];
    }

    /**
     * @brief Process the next batch of entries for one job.
     * @param int $ownerUserId Owner namespace.
     * @param string $jobId Job identifier.
     * @return array<string, mixed> Progress payload.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function tickJob(int $ownerUserId, string $jobId): array
    {
        $meta = $this->loadMeta($ownerUserId, $jobId);
        if ($meta === null) {
            throw new \RuntimeException('zip_extract.session_not_found');
        }

        $phase = (string) ($meta['phase'] ?? '');
        if ($phase === 'done') {
            return $this->buildProgressPayload($meta, true);
        }
        if ($phase === 'failed' || $phase === 'cancelled') {
            return $this->buildProgressPayload($meta, true);
        }

        $limits = ZipExtractLimits::fromJobMeta($meta);
        $startedAt = (float) ($meta['started_at'] ?? microtime(true));
        if ($this->isJobTimedOut($startedAt, $limits)) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.limit_time');

            throw new \RuntimeException('zip_extract.limit_time');
        }

        if ($phase === 'finalizing') {
            return $this->finalizeJob($ownerUserId, $jobId, $meta);
        }

        if ($phase === 'pending') {
            return $this->tickDecryptPhase($ownerUserId, $jobId, $meta);
        }

        if ($phase === 'scanning') {
            return $this->tickScanPhase($ownerUserId, $jobId, $meta, $limits, $startedAt);
        }

        $tempZipPath = $this->tempZipPath($ownerUserId, $jobId);
        if (!is_readable($tempZipPath)) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.invalid');
            throw new \RuntimeException('zip_extract.invalid');
        }

        $zip = new \ZipArchive();
        $openResult = $zip->open($tempZipPath);
        if ($openResult !== true) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.invalid');
            throw new \RuntimeException('zip_extract.invalid');
        }

        try {
            /** @var array<int, array<string, mixed>> $entries */
            $entries = $meta['entries'] ?? [];
            $nextIndex = (int) ($meta['next_index'] ?? 0);
            $batchEnd = min(count($entries), $nextIndex + max(1, $limits->batchSize));
            $extractRootFolderId = (int) ($meta['extract_root_folder_id'] ?? 0);
            $extractRoot = $extractRootFolderId > 0
                ? $this->folderTreeService->resolveCurrentFolder($ownerUserId, $extractRootFolderId)
                : null;
            if ($extractRootFolderId > 0 && !$extractRoot instanceof Folder) {
                $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.invalid');
                throw new \RuntimeException('zip_extract.invalid');
            }

            $targetFolderId = (int) ($meta['target_folder_id'] ?? 0);
            $baseFolder = $extractRoot instanceof Folder
                ? $extractRoot
                : $this->folderTreeService->resolveCurrentFolder($ownerUserId, $targetFolderId > 0 ? $targetFolderId : null);

            $conflictPolicy = (string) ($meta['conflict_policy'] ?? self::CONFLICT_ABORT);

            for ($i = $nextIndex; $i < $batchEnd; ++$i) {
                if ($this->isJobTimedOut($startedAt, $limits)) {
                    $meta['next_index'] = $i;
                    $this->saveMeta($ownerUserId, $jobId, $meta);
                    throw new \RuntimeException('zip_extract.limit_time');
                }

                /** @var array<string, mixed> $entry */
                $entry = $entries[$i];
                $zipIndex = (int) ($entry['zip_index'] ?? -1);
                $sanitizedPath = (string) ($entry['sanitized_path'] ?? '');
                $isDir = (bool) ($entry['is_dir'] ?? false);
                $meta['current_entry'] = $sanitizedPath;

                if ($isDir) {
                    $this->folderPathMaterializerService->ensureFolderPathFromRelative(
                        $ownerUserId,
                        $baseFolder,
                        $sanitizedPath,
                        $conflictPolicy,
                        $meta
                    );
                    continue;
                }

                $result = $this->extractFileEntry(
                    $ownerUserId,
                    $zip,
                    $zipIndex,
                    $sanitizedPath,
                    $baseFolder,
                    $conflictPolicy,
                    $meta
                );

                if ($result === 'abort') {
                    $meta['next_index'] = $i;
                    $this->saveMeta($ownerUserId, $jobId, $meta);
                    $this->rollbackJobArtifacts($ownerUserId, $meta);
                    $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.conflict_abort');
                    throw new \RuntimeException('zip_extract.conflict_abort');
                }
                if ($result === 'skipped') {
                    ++$meta['skipped'];
                } else {
                    ++$meta['extracted'];
                }
            }

            $meta['next_index'] = $batchEnd;
            if ($batchEnd >= count($entries)) {
                $meta['phase'] = 'finalizing';
            }
            $this->saveMeta($ownerUserId, $jobId, $meta);

            if ($meta['phase'] === 'finalizing') {
                return $this->finalizeJob($ownerUserId, $jobId, $meta);
            }

            return $this->buildProgressPayload($meta, false);
        } finally {
            $zip->close();
        }
    }

    /**
     * @brief Decrypt the source archive into the job temp path (one tick step).
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job meta.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function tickDecryptPhase(int $ownerUserId, string $jobId, array $meta): array
    {
        $sourcePath = (string) ($meta['source_storage_path'] ?? '');
        if ($sourcePath === '' || !is_readable($sourcePath)) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.invalid');
            throw new \RuntimeException('zip_extract.invalid');
        }

        $tempZipPath = $this->tempZipPath($ownerUserId, $jobId);
        $out = fopen($tempZipPath, 'wb');
        if ($out === false) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.decrypt_failed');
            throw new \RuntimeException('zip_extract.decrypt_failed');
        }
        try {
            $this->fileEncryptionService->streamDecryptStorageToHandle($sourcePath, $out);
        } catch (\RuntimeException) {
            @unlink($tempZipPath);
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.decrypt_failed');
            throw new \RuntimeException('zip_extract.decrypt_failed');
        } finally {
            fclose($out);
        }

        $meta['phase'] = 'scanning';
        $meta['started_at'] = microtime(true);
        $meta['current_entry'] = '';
        $this->saveMeta($ownerUserId, $jobId, $meta);

        return $this->buildProgressPayload($meta, false);
    }

    /**
     * @brief Scan decrypted archive, enforce quota, and prepare extraction entries.
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job meta.
     * @param ZipExtractLimits $limits Frozen job limits.
     * @param float $startedAt Job timer origin.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function tickScanPhase(
        int $ownerUserId,
        string $jobId,
        array $meta,
        ZipExtractLimits $limits,
        float $startedAt,
    ): array {
        $tempZipPath = $this->tempZipPath($ownerUserId, $jobId);
        if (!is_readable($tempZipPath)) {
            $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.invalid');
            throw new \RuntimeException('zip_extract.invalid');
        }

        try {
            $scan = $this->scanZipArchive($tempZipPath, $startedAt, $limits);
        } catch (\RuntimeException $e) {
            @unlink($tempZipPath);
            $this->failJob($ownerUserId, $jobId, $meta, $e->getMessage());
            throw $e;
        }

        $sourceId = (int) ($meta['source_file_id'] ?? 0);
        $sourceFile = $this->sharedFileRepository->find($sourceId);
        $sourceZipBytes = $sourceFile instanceof SharedFile ? (int) $sourceFile->getByteSize() : 0;
        $deleteZipAfter = (bool) ($meta['delete_zip_after'] ?? false);
        $quotaBytes = $deleteZipAfter
            ? max(0, $scan['total_bytes'] - $sourceZipBytes)
            : $scan['total_bytes'];
        if ($quotaBytes > 0) {
            try {
                $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, $quotaBytes);
            } catch (\RuntimeException $e) {
                @unlink($tempZipPath);
                if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                    $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.quota_exceeded');
                    throw new \RuntimeException('zip_extract.quota_exceeded', 0, $e);
                }

                throw $e;
            }
        }

        $mode = (string) ($meta['mode'] ?? self::MODE_HERE);
        $conflictPolicy = (string) ($meta['conflict_policy'] ?? self::CONFLICT_ABORT);
        $targetFolderId = (int) ($meta['target_folder_id'] ?? 0);
        $targetFolder = $this->folderTreeService->resolveCurrentFolder($ownerUserId, $targetFolderId > 0 ? $targetFolderId : null);
        $extractRootFolderId = 0;

        if ($mode === self::MODE_SUBFOLDER && $sourceFile instanceof SharedFile) {
            $subfolderName = $this->deriveSubfolderName($sourceFile->getOriginalFileName());
            $resolved = $this->folderPathMaterializerService->resolveFolderSegmentName(
                $ownerUserId,
                $targetFolder,
                $subfolderName,
                $conflictPolicy,
                $meta
            );
            if ($resolved['action'] === 'abort' || $resolved['action'] === 'skip') {
                @unlink($tempZipPath);
                $this->failJob($ownerUserId, $jobId, $meta, 'zip_extract.conflict_abort');
                throw new \RuntimeException('zip_extract.conflict_abort');
            }
            $folder = $resolved['folder'];
            if ($folder === null) {
                $folder = new Folder($ownerUserId, $resolved['name'], $targetFolder);
                $this->entityManager->persist($folder);
                $this->entityManager->flush();
                $fid = (int) ($folder->getId() ?? 0);
                if ($fid > 0) {
                    /** @var list<int> $createdFolderIds */
                    $createdFolderIds = $meta['created_folder_ids'] ?? [];
                    $createdFolderIds[] = $fid;
                    $meta['created_folder_ids'] = $createdFolderIds;
                }
            }
            $extractRootFolderId = (int) ($folder->getId() ?? 0);
        }

        $meta['entries'] = $scan['entries'];
        $meta['total_entries'] = $scan['file_count'];
        $meta['total_bytes'] = $scan['total_bytes'];
        $meta['extract_root_folder_id'] = $extractRootFolderId;
        $meta['phase'] = 'extracting';
        $meta['next_index'] = 0;
        $meta['current_entry'] = '';
        $this->saveMeta($ownerUserId, $jobId, $meta);

        return $this->buildProgressPayload($meta, false);
    }

    /**
     * @brief Whether the job exceeded its allowed wall-clock duration.
     * @param float $startedAt Unix timestamp with fraction.
     * @param ZipExtractLimits $limits Job limits.
     * @return bool
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function isJobTimedOut(float $startedAt, ZipExtractLimits $limits): bool
    {
        return microtime(true) - $startedAt > $limits->maxSeconds;
    }

    /**
     * @brief Cancel a running job and rollback created artifacts.
     * @param int $ownerUserId Owner namespace.
     * @param string $jobId Job identifier.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function cancelJob(int $ownerUserId, string $jobId): array
    {
        $meta = $this->loadMeta($ownerUserId, $jobId);
        if ($meta === null) {
            throw new \RuntimeException('zip_extract.session_not_found');
        }

        $phase = (string) ($meta['phase'] ?? '');
        if ($phase === 'done') {
            return $this->buildProgressPayload($meta, true);
        }

        $this->rollbackJobArtifacts($ownerUserId, $meta);
        $meta['phase'] = 'cancelled';
        $meta['error_message'] = 'zip_extract.cancelled';
        $this->saveMeta($ownerUserId, $jobId, $meta);
        $this->cleanupJobFiles($ownerUserId, $jobId);

        return $this->buildProgressPayload($meta, true);
    }

    /**
     * @brief Map service exception message keys to flash translation keys.
     * @param \RuntimeException $exception Thrown service exception.
     * @return string Message key in messages domain.
     * @date 2026-06-24
     * @author Stephane H.
     */
    public static function mapExceptionToFlashKey(\RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'zip_extract.not_zip' => 'files.flash.extract_not_zip',
            'zip_extract.forbidden', 'zip_extract.not_found' => 'files.flash.not_owner',
            'zip_extract.session_not_found' => 'files.flash.extract_session_not_found',
            'zip_extract.password_protected' => 'files.flash.extract_password_protected',
            'zip_extract.invalid' => 'files.flash.extract_invalid',
            'zip_extract.limit_files' => 'files.flash.extract_limit_files',
            'zip_extract.limit_bytes' => 'files.flash.extract_limit_bytes',
            'zip_extract.limit_time' => 'files.flash.extract_limit_time',
            'zip_extract.limit_ratio' => 'files.flash.extract_limit_ratio',
            'zip_extract.quota_exceeded' => 'files.flash.quota_exceeded',
            'zip_extract.conflict_abort' => 'files.flash.extract_conflict_abort',
            'zip_extract.cancelled' => 'files.flash.extract_cancelled',
            default => 'files.flash.extract_failed',
        };
    }

    /**
     * @param string $mode Extraction destination mode.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function assertValidMode(string $mode): void
    {
        if (!\in_array($mode, [self::MODE_HERE, self::MODE_SUBFOLDER], true)) {
            throw new \RuntimeException('zip_extract.invalid');
        }
    }

    /**
     * @param string $policy Conflict resolution policy.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function assertValidConflictPolicy(string $policy): void
    {
        if (!\in_array($policy, [self::CONFLICT_ABORT, self::CONFLICT_SKIP, self::CONFLICT_RENAME], true)) {
            throw new \RuntimeException('zip_extract.invalid');
        }
    }

    /**
     * @param string $zipPath Readable decrypted ZIP path.
     * @param float $startedAt Job start timestamp for timeout checks.
     * @param ZipExtractLimits $limits Effective limits for this job.
     * @return array{entries: list<array<string, mixed>>, file_count: int, total_bytes: int}
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function scanZipArchive(string $zipPath, float $startedAt, ZipExtractLimits $limits): array
    {
        $zip = new \ZipArchive();
        $openResult = $zip->open($zipPath);
        if ($openResult !== true) {
            throw new \RuntimeException('zip_extract.invalid');
        }

        $entries = [];
        $fileCount = 0;
        $totalBytes = 0;
        $numEntries = $zip->numFiles;

        for ($i = 0; $i < $numEntries; ++$i) {
            if ($this->isJobTimedOut($startedAt, $limits)) {
                $zip->close();
                throw new \RuntimeException('zip_extract.limit_time');
            }

            $stat = $zip->statIndex($i);
            if (!\is_array($stat)) {
                continue;
            }

            $rawName = (string) ($stat['name'] ?? '');
            if ($rawName === '') {
                continue;
            }

            $encryptionMethod = (int) ($stat['encryption_method'] ?? 0);
            if ($encryptionMethod !== 0) {
                $zip->close();
                throw new \RuntimeException('zip_extract.password_protected');
            }

            $isDir = str_ends_with($rawName, '/');
            $sanitized = ZipEntryNameSanitizer::sanitizeEntryPath($rawName, $i);
            $uncompressed = (int) ($stat['size'] ?? 0);
            $compressed = (int) ($stat['comp_size'] ?? 0);

            if (!$isDir) {
                if ($fileCount >= $limits->maxFileCount) {
                    $zip->close();
                    throw new \RuntimeException('zip_extract.limit_files');
                }
                if ($totalBytes + $uncompressed > $limits->maxTotalBytes) {
                    $zip->close();
                    throw new \RuntimeException('zip_extract.limit_bytes');
                }
                if ($compressed > 0 && $uncompressed / $compressed > $limits->maxCompressionRatio) {
                    $zip->close();
                    throw new \RuntimeException('zip_extract.limit_ratio');
                }
                ++$fileCount;
                $totalBytes += $uncompressed;
            }

            $entries[] = [
                'zip_index' => $i,
                'sanitized_path' => $sanitized,
                'is_dir' => $isDir,
                'size' => $uncompressed,
            ];
        }

        $zip->close();

        return [
            'entries' => $entries,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
        ];
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param \ZipArchive $zip Open archive handle.
     * @param int $zipIndex Entry index inside archive.
     * @param string $sanitizedPath Safe relative path.
     * @param Folder|null $baseFolder Extraction root folder.
     * @param string $conflictPolicy Conflict policy constant.
     * @param array<string, mixed> $meta Mutable job meta (tracks created ids).
     * @return string One of extracted|skipped|abort.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function extractFileEntry(
        int $ownerUserId,
        \ZipArchive $zip,
        int $zipIndex,
        string $sanitizedPath,
        ?Folder $baseFolder,
        string $conflictPolicy,
        array &$meta,
    ): string {
        $parts = explode('/', $sanitizedPath);
        $fileName = (string) array_pop($parts);
        if ($fileName === '') {
            return 'skipped';
        }

        $folderResult = $this->folderPathMaterializerService->ensureFolderPathFromRelative(
            $ownerUserId,
            $baseFolder,
            implode('/', $parts),
            $conflictPolicy,
            $meta
        );
        if ($folderResult === FolderPathMaterializerService::CONFLICT_ABORT) {
            return 'abort';
        }
        /** @var Folder|null $parentFolder */
        $parentFolder = $folderResult;

        $resolvedName = $this->folderPathMaterializerService->resolveFileName(
            $ownerUserId,
            $parentFolder,
            $fileName,
            $conflictPolicy
        );
        if ($resolvedName === null) {
            return $conflictPolicy === self::CONFLICT_SKIP ? 'skipped' : 'abort';
        }

        $tempExtract = tempnam(sys_get_temp_dir(), 'zex_');
        if ($tempExtract === false) {
            throw new \RuntimeException('zip_extract.storage_failed');
        }

        $plainContent = $zip->getFromIndex($zipIndex);
        if ($plainContent === false) {
            @unlink($tempExtract);
            throw new \RuntimeException('zip_extract.invalid');
        }
        if (file_put_contents($tempExtract, $plainContent) === false) {
            @unlink($tempExtract);
            throw new \RuntimeException('zip_extract.storage_failed');
        }

        $relativeDir = \sprintf('var/shared/%d', $ownerUserId);
        $absoluteDir = $this->projectDir.'/'.$relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            @unlink($tempExtract);
            throw new \RuntimeException('zip_extract.storage_failed');
        }

        $storageName = bin2hex(random_bytes(16)).'.dat';
        $absolutePath = $absoluteDir.'/'.$storageName;

        try {
            $plainSize = $this->fileEncryptionService->encryptPlainFileToV2Storage($tempExtract, $absolutePath);
        } finally {
            @unlink($tempExtract);
        }

        $sharedFile = new SharedFile(
            $ownerUserId,
            $absolutePath,
            'private',
            bin2hex(random_bytes(16)),
            $resolvedName,
            $plainSize,
            null,
            null
        );
        $sharedFile->setFolder($parentFolder);
        $this->entityManager->persist($sharedFile);
        $this->entityManager->flush();

        $fileId = (int) ($sharedFile->getId() ?? 0);
        if ($fileId > 0) {
            /** @var list<int> $createdFileIds */
            $createdFileIds = $meta['created_file_ids'] ?? [];
            $createdFileIds[] = $fileId;
            $meta['created_file_ids'] = $createdFileIds;
        }

        if ($parentFolder instanceof Folder) {
            $this->applyFolderPoliciesToUploadedFile($sharedFile, $parentFolder, $ownerUserId);
        }

        return 'extracted';
    }

    /**
     * @param string $originalFileName ZIP display name.
     * @return string Subfolder display name.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function deriveSubfolderName(string $originalFileName): string
    {
        $base = pathinfo($originalFileName, PATHINFO_FILENAME);
        if ($base === '') {
            return 'archive';
        }

        return $base;
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job meta.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function finalizeJob(int $ownerUserId, string $jobId, array $meta): array
    {
        if ((bool) ($meta['delete_zip_after'] ?? false)) {
            $sourceId = (int) ($meta['source_file_id'] ?? 0);
            if ($sourceId > 0) {
                $source = $this->sharedFileRepository->find($sourceId);
                if ($source instanceof SharedFile && $source->getOwnerUserId() === $ownerUserId) {
                    $this->removeSharedFileAggregate($source);
                }
            }
        }

        $meta['phase'] = 'done';
        $meta['current_entry'] = '';
        $this->saveMeta($ownerUserId, $jobId, $meta);
        $this->cleanupJobFiles($ownerUserId, $jobId);

        return $this->buildProgressPayload($meta, true);
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job meta.
     * @param string $errorKey Error translation key suffix.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function failJob(int $ownerUserId, string $jobId, array $meta, string $errorKey): void
    {
        $meta['phase'] = 'failed';
        $meta['error_message'] = $errorKey;
        $this->saveMeta($ownerUserId, $jobId, $meta);
        $this->cleanupJobFiles($ownerUserId, $jobId);
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param array<string, mixed> $meta Job meta.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function rollbackJobArtifacts(int $ownerUserId, array &$meta): void
    {
        /** @var list<int> $fileIds */
        $fileIds = array_reverse($meta['created_file_ids'] ?? []);
        foreach ($fileIds as $fileId) {
            $file = $this->sharedFileRepository->find($fileId);
            if ($file instanceof SharedFile && $file->getOwnerUserId() === $ownerUserId) {
                $this->removeSharedFileAggregate($file);
            }
        }

        /** @var list<int> $folderIds */
        $folderIds = array_reverse($meta['created_folder_ids'] ?? []);
        foreach ($folderIds as $folderId) {
            $folder = $this->folderRepository->find($folderId);
            if (!$folder instanceof Folder || $folder->getOwnerUserId() !== $ownerUserId) {
                continue;
            }
            $files = $this->sharedFileRepository->findBy(['ownerUserId' => $ownerUserId, 'folder' => $folder]);
            if ($files !== []) {
                continue;
            }
            $children = $this->folderRepository->findChildrenForOwner($ownerUserId, $folder);
            if ($children !== []) {
                continue;
            }
            $this->entityManager->remove($folder);
        }
        $this->entityManager->flush();

        $meta['created_file_ids'] = [];
        $meta['created_folder_ids'] = [];
    }

    /**
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function removeSharedFileAggregate(SharedFile $sharedFile): void
    {
        $path = $sharedFile->getStoragePath();
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
        $token = $sharedFile->getPublicToken();
        $this->shareGrantRepository->deleteBySharedFileId((int) $sharedFile->getId());
        $this->publicDownloadChallengeRepository->deleteByPublicToken($token);
        $this->entityManager->remove($sharedFile);
        $this->entityManager->flush();
    }

    /**
     * @param SharedFile $sharedFile Newly extracted file.
     * @param Folder $targetFolder Folder where the file was stored.
     * @param int $ownerUserId Owner user identifier.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function applyFolderPoliciesToUploadedFile(SharedFile $sharedFile, Folder $targetFolder, int $ownerUserId): void
    {
        if ($targetFolder->isPublicShareEnabled()) {
            $this->publicShareService->enablePublic($sharedFile, $targetFolder->getPublicShareExpiresAt());
        }

        $subtreeFolders = $this->folderTreeService->collectSubtreeFolders($ownerUserId, $targetFolder);
        $folderIds = [];
        foreach ($subtreeFolders as $subFolder) {
            $fid = $subFolder->getId();
            if ($fid !== null && $fid > 0) {
                $folderIds[] = $fid;
            }
        }
        $newFileId = (int) $sharedFile->getId();

        $granteeIntents = [];
        foreach ($targetFolder->getFriendsShareUserIds() as $granteeUserId) {
            if ($granteeUserId <= 0 || $granteeUserId === $ownerUserId) {
                continue;
            }
            $hasPriorGrantInSubtree = $this->shareGrantRepository->hasAnyGrantForOwnerFolderSubtreeGrantee($ownerUserId, $folderIds, $granteeUserId, $newFileId);
            $activeTemplateGrant = $this->shareGrantRepository->findOneActiveGrantForOwnerFolderSubtreeGrantee($ownerUserId, $folderIds, $granteeUserId, $newFileId);
            if ($hasPriorGrantInSubtree && !$activeTemplateGrant instanceof ShareGrant) {
                continue;
            }
            $expiresAt = $activeTemplateGrant instanceof ShareGrant ? $activeTemplateGrant->getExpiresAt() : null;
            $granteeIntents[] = [
                'user_id' => $granteeUserId,
                'expires_at' => $expiresAt,
            ];
        }
        if ($granteeIntents !== []) {
            $this->friendsShareService->applyFriendsIntent($sharedFile, $granteeIntents, false);
        }
    }

    /**
     * @param array<string, mixed> $meta Job meta.
     * @param bool $terminal Whether job reached a terminal phase.
     * @return array<string, mixed>
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function buildProgressPayload(array $meta, bool $terminal): array
    {
        $total = (int) ($meta['total_entries'] ?? 0);
        $doneCount = (int) ($meta['extracted'] ?? 0) + (int) ($meta['skipped'] ?? 0);
        $phase = (string) ($meta['phase'] ?? 'pending');
        $isDone = $terminal && ($phase === 'done' || $phase === 'failed' || $phase === 'cancelled');

        return [
            'phase' => $phase,
            'extracted' => (int) ($meta['extracted'] ?? 0),
            'skipped' => (int) ($meta['skipped'] ?? 0),
            'total' => $total,
            'total_bytes' => (int) ($meta['total_bytes'] ?? 0),
            'percent' => $this->computeProgressPercent($phase, $doneCount, $total),
            'current_entry' => (string) ($meta['current_entry'] ?? ''),
            'done' => $isDone,
            'error_message' => (string) ($meta['error_message'] ?? ''),
        ];
    }

    /**
     * @brief Map job phase and counters to a 0–100 progress percentage.
     * @param string $phase Current job phase.
     * @param int $doneCount Extracted plus skipped file entries.
     * @param int $total Total file entries when known.
     * @return int
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function computeProgressPercent(string $phase, int $doneCount, int $total): int
    {
        return match ($phase) {
            'pending' => 0,
            'scanning' => 8,
            'finalizing' => 99,
            'done' => 100,
            'extracting' => $total > 0
                ? min(98, 10 + (int) round(88 * $doneCount / $total))
                : 10,
            default => 0,
        };
    }

    /**
     * @param int $ownerUserId Owner id.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function purgeExpiredForOwner(int $ownerUserId): void
    {
        $dir = $this->sessionBaseDir($ownerUserId);
        if (!is_dir($dir)) {
            return;
        }
        $now = time();
        $globbed = glob($dir.'/*.json');
        $metaFiles = \is_array($globbed) ? $globbed : [];
        foreach ($metaFiles as $metaFile) {
            $raw = @file_get_contents($metaFile);
            if (!is_string($raw)) {
                continue;
            }
            try {
                /** @var array<string, mixed> $m */
                $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                $created = (int) ($m['created_at'] ?? 0);
                if ($created > 0 && ($now - $created) > self::SESSION_TTL_SECONDS) {
                    $jobId = basename($metaFile, self::META_SUFFIX);
                    $this->cleanupJobFiles($ownerUserId, $jobId);
                }
            } catch (\JsonException) {
                continue;
            }
        }
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function cleanupJobFiles(int $ownerUserId, string $jobId): void
    {
        @unlink($this->metaPath($ownerUserId, $jobId));
        @unlink($this->tempZipPath($ownerUserId, $jobId));
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @return array<string, mixed>|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function loadMeta(int $ownerUserId, string $jobId): ?array
    {
        $path = $this->metaPath($ownerUserId, $jobId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        try {
            /** @var array<string, mixed> $m */
            $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $m;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Meta payload.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function saveMeta(int $ownerUserId, string $jobId, array $meta): void
    {
        $path = $this->metaPath($ownerUserId, $jobId);
        file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }

    /**
     * @param int $ownerUserId Owner id.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function sessionBaseDir(int $ownerUserId): string
    {
        return $this->projectDir.'/var/extract/'.$ownerUserId;
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function metaPath(int $ownerUserId, string $jobId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$jobId.self::META_SUFFIX;
    }

    /**
     * @param int $ownerUserId Owner id.
     * @param string $jobId Job id.
     * @return string
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function tempZipPath(int $ownerUserId, string $jobId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$jobId.self::TEMP_ZIP_SUFFIX;
    }
}
