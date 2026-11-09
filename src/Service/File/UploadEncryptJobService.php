<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Service\Admin\ServerDiskSpaceService;
use App\Service\Audit\UploadDiagnosticLogger;
use App\Service\Infrastructure\RuntimePermissionFailure;
use App\Service\Share\FolderTreeService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @brief Background upload-encrypt jobs with tick progress, cancel, and task listing.
 * @date 2026-11-09
 * @author Stephane H.
 */
final class UploadEncryptJobService
{
    public const TYPE = 'upload_encrypt';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    private const META_SUFFIX = '.json';

    private const CANCEL_SUFFIX = '.cancel';

    private const SESSION_TTL_SECONDS = 86400;

    private const TERMINAL_RETENTION_SECONDS = 86400;

    /** @brief Plaintext bytes processed per HTTP tick (~8 MiB) — stays under proxy/PHP timeouts. */
    private const TICK_PLAIN_BUDGET = 8388608;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FolderTreeService $folderTreeService,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly FolderPathMaterializerService $folderPathMaterializerService,
        private readonly ServerDiskSpaceService $serverDiskSpaceService,
        private readonly ChunkedUploadService $chunkedUploadService,
        private readonly UploadDiagnosticLogger $uploadDiagnosticLogger,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @brief Validate assembled chunk session and create a running encrypt job.
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Chunk upload session id.
     * @param int $maxUploadBytes Max allowed plaintext size.
     * @return array<string, mixed> Public task payload.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function createFromUploadSession(int $ownerUserId, string $uploadId, int $maxUploadBytes): array
    {
        $this->purgeExpiredForOwner($ownerUserId);
        $jobCtx = [
            'owner_user_id' => $ownerUserId,
            'upload_id' => $uploadId,
            'max_upload_bytes' => $maxUploadBytes,
        ];
        $session = $this->chunkedUploadService->loadSessionMeta($ownerUserId, $uploadId);
        if ($session === null) {
            $this->throwLogged('upload.encrypt.session', 'chunk_upload.session_not_found', $jobCtx);
        }

        $expectedTotal = (int) ($session['expected_bytes'] ?? 0);
        $received = (int) ($session['received_bytes'] ?? 0);
        $jobCtx['plain_total'] = $expectedTotal;
        $jobCtx['received_bytes'] = $received;
        if ($received !== $expectedTotal || $expectedTotal < 1) {
            $this->throwLogged('upload.encrypt.incomplete', 'chunk_upload.incomplete', $jobCtx);
        }
        if ($expectedTotal > $maxUploadBytes) {
            $this->throwLogged('upload.encrypt.too_large', 'chunk_upload.too_large', $jobCtx);
        }

        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, $expectedTotal);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                $this->throwLogged('upload.encrypt.quota', 'chunk_upload.quota_exceeded', $jobCtx, $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.encrypt.quota', $jobCtx, $e);
            throw $e;
        }

        try {
            // Plaintext .part already occupies disk; only need room for the cipher output.
            $this->serverDiskSpaceService->assertFreeBytesForRemainingCipher($expectedTotal);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === ServerDiskSpaceService::EXCEPTION_DISK_FULL) {
                $this->throwLogged('upload.encrypt.disk', 'chunk_upload.disk_full', $jobCtx, $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.encrypt.disk', $jobCtx, $e);
            throw $e;
        }

        $resolved = $this->resolveDisplayAndFolder($ownerUserId, $session);
        $partPath = $this->chunkedUploadService->resolvePartPath($ownerUserId, $uploadId);
        if (!is_readable($partPath)) {
            $this->throwLogged('upload.encrypt.part', 'chunk_upload.part_missing', $jobCtx + ['path' => $partPath]);
        }

        $relativeDir = \sprintf('var/shared/%d', $ownerUserId);
        $absoluteDir = $this->projectDir.'/'.$relativeDir;
        $this->ensureWritableDirectory($absoluteDir);
        $this->ensureWritableDirectory($this->jobsBaseDir($ownerUserId));

        $taskId = bin2hex(random_bytes(16));
        $storageName = bin2hex(random_bytes(16)).'.dat';
        $cipherPath = $absoluteDir.'/'.$storageName;

        $meta = [
            'task_id' => $taskId,
            'type' => self::TYPE,
            'status' => self::STATUS_RUNNING,
            'owner_user_id' => $ownerUserId,
            'upload_id' => $uploadId,
            'label' => $resolved['display_name'],
            'folder_id' => $resolved['folder_id'],
            'part_path' => $partPath,
            'cipher_path' => $cipherPath,
            'plain_total' => $expectedTotal,
            'plain_done' => 0,
            'encrypt_state' => null,
            'shared_file_id' => null,
            'error_key' => null,
            'modal_key' => 'files_upload',
            'notified' => false,
            'created_at' => time(),
            'updated_at' => time(),
            'finished_at' => null,
        ];
        $this->saveMeta($ownerUserId, $taskId, $meta);

        $this->uploadDiagnosticLogger->info('upload.encrypt.created', [
            'owner_user_id' => $ownerUserId,
            'upload_id' => $uploadId,
            'task_id' => $taskId,
            'plain_total' => $expectedTotal,
            'path' => $cipherPath,
            'part_path' => $partPath,
        ]);

        return $this->toPublicArray($meta);
    }

    /**
     * @brief Advance encryption for one tick budget.
     * @param int $ownerUserId Owner id.
     * @param string $taskId Task id.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function tick(int $ownerUserId, string $taskId): array
    {
        $meta = $this->loadMeta($ownerUserId, $taskId);
        if ($meta === null) {
            $this->throwLogged('upload.encrypt.not_found', 'upload_encrypt.not_found', [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
                'path' => $this->metaPath($ownerUserId, $taskId),
            ]);
        }
        if (($meta['status'] ?? '') !== self::STATUS_RUNNING) {
            return $this->toPublicArray($meta);
        }
        if ($this->isCancelRequested($ownerUserId, $taskId)) {
            return $this->cancel($ownerUserId, $taskId);
        }

        $plainTotal = (int) ($meta['plain_total'] ?? 0);
        try {
            $this->serverDiskSpaceService->assertFreeBytesForRemainingCipher(
                max(0, $plainTotal - (int) ($meta['plain_done'] ?? 0))
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === ServerDiskSpaceService::EXCEPTION_DISK_FULL) {
                return $this->fail($ownerUserId, $meta, 'files.flash.disk_full', $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.encrypt.disk', [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
            ], $e);
            throw $e;
        }

        $state = isset($meta['encrypt_state']) && \is_array($meta['encrypt_state']) ? $meta['encrypt_state'] : null;
        $cipherPath = (string) ($meta['cipher_path'] ?? '');
        $partPath = (string) ($meta['part_path'] ?? '');

        try {
            $tick = $this->fileEncryptionService->encryptPlainFileToV2StorageTick(
                $partPath,
                $cipherPath,
                $state,
                self::TICK_PLAIN_BUDGET,
                function () use ($ownerUserId, $taskId): bool {
                    return $this->isCancelRequested($ownerUserId, $taskId);
                },
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'file.encryption.cancelled') {
                return $this->cancel($ownerUserId, $taskId);
            }
            if (RuntimePermissionFailure::matches($e)) {
                return $this->fail($ownerUserId, $meta, RuntimePermissionFailure::FLASH_KEY, $e);
            }

            return $this->fail($ownerUserId, $meta, 'files.flash.storage_failed', $e);
        }

        $meta['encrypt_state'] = $tick['state'];
        $meta['plain_done'] = (int) ($tick['state']['plain_done'] ?? 0);
        $meta['updated_at'] = time();

        if (!$tick['complete']) {
            $this->saveMeta($ownerUserId, $taskId, $meta);

            return $this->toPublicArray($meta);
        }

        /** @var array{plain_total: int, content_crc32: int, content_sha256: string} $fingerprints */
        $fingerprints = $tick['fingerprints'];
        $folderId = (int) ($meta['folder_id'] ?? 0);
        $folder = $folderId > 0
            ? $this->folderTreeService->resolveCurrentFolder($ownerUserId, $folderId)
            : null;

        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, (int) $fingerprints['plain_total']);
        } catch (\RuntimeException $e) {
            $this->cleanupCipherArtifacts($cipherPath);
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                return $this->fail($ownerUserId, $meta, 'files.flash.quota_exceeded', $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.encrypt.quota', [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
            ], $e);
            throw $e;
        }

        $sharedFile = new SharedFile(
            $ownerUserId,
            $cipherPath,
            'private',
            bin2hex(random_bytes(16)),
            (string) ($meta['label'] ?? 'file'),
            (int) $fingerprints['plain_total'],
            null,
            null
        );
        $sharedFile->applyContentFingerprints($fingerprints);
        if ($folder instanceof Folder) {
            $sharedFile->setFolder($folder);
        }
        $this->entityManager->persist($sharedFile);
        $this->entityManager->flush();

        $uploadId = (string) ($meta['upload_id'] ?? '');
        if ($uploadId !== '') {
            $this->chunkedUploadService->abortSession($ownerUserId, $uploadId);
        }

        $meta['status'] = self::STATUS_DONE;
        $meta['plain_done'] = (int) $fingerprints['plain_total'];
        $meta['shared_file_id'] = $sharedFile->getId();
        $meta['finished_at'] = time();
        $meta['updated_at'] = time();
        $this->saveMeta($ownerUserId, $taskId, $meta);
        @unlink($this->cancelPath($ownerUserId, $taskId));

        $this->uploadDiagnosticLogger->info('upload.encrypt.done', [
            'owner_user_id' => $ownerUserId,
            'task_id' => $taskId,
            'upload_id' => $uploadId,
            'plain_total' => (int) $fingerprints['plain_total'],
            'shared_file_id' => $sharedFile->getId(),
            'path' => $cipherPath,
        ]);

        return $this->toPublicArray($meta);
    }

    /**
     * @brief Request cancel and perform cleanup if running.
     * @param int $ownerUserId Owner id.
     * @param string $taskId Task id.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function cancel(int $ownerUserId, string $taskId): array
    {
        $meta = $this->loadMeta($ownerUserId, $taskId);
        if ($meta === null) {
            $this->throwLogged('upload.encrypt.not_found', 'upload_encrypt.not_found', [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
                'path' => $this->metaPath($ownerUserId, $taskId),
            ]);
        }
        if (\in_array(($meta['status'] ?? ''), [self::STATUS_DONE, self::STATUS_CANCELLED], true)) {
            return $this->toPublicArray($meta);
        }

        $cancelPath = $this->cancelPath($ownerUserId, $taskId);
        if (@file_put_contents($cancelPath, '1') === false) {
            $this->throwLogged('upload.encrypt.cancel_flag', RuntimePermissionFailure::EXCEPTION, [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
                'path' => $cancelPath,
            ]);
        }
        $cipherPath = (string) ($meta['cipher_path'] ?? '');
        $this->cleanupCipherArtifacts($cipherPath);
        $uploadId = (string) ($meta['upload_id'] ?? '');
        if ($uploadId !== '') {
            $this->chunkedUploadService->abortSession($ownerUserId, $uploadId);
        }

        $meta['status'] = self::STATUS_CANCELLED;
        $meta['finished_at'] = time();
        $meta['updated_at'] = time();
        $this->saveMeta($ownerUserId, $taskId, $meta);
        @unlink($cancelPath);

        $this->uploadDiagnosticLogger->info('upload.encrypt.cancelled', [
            'owner_user_id' => $ownerUserId,
            'task_id' => $taskId,
            'upload_id' => $uploadId,
            'path' => $cipherPath,
        ]);

        return $this->toPublicArray($meta);
    }

    /**
     * @brief List tasks for the owner (running + recent terminal).
     * @param int $ownerUserId Owner id.
     * @return list<array<string, mixed>>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function listForOwner(int $ownerUserId): array
    {
        $this->purgeExpiredForOwner($ownerUserId);
        $dir = $this->jobsBaseDir($ownerUserId);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $files = glob($dir.'/*'.self::META_SUFFIX) ?: [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if (!is_string($raw)) {
                continue;
            }
            try {
                /** @var array<string, mixed> $meta */
                $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $out[] = $this->toPublicArray($meta);
        }
        usort($out, static fn (array $a, array $b): int => ((int) ($b['updatedAt'] ?? 0)) <=> ((int) ($a['updatedAt'] ?? 0)));

        return $out;
    }

    /**
     * @brief Mark terminal tasks as notified (toast consumed).
     * @param int $ownerUserId Owner id.
     * @param list<string> $taskIds Task ids.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function acknowledge(int $ownerUserId, array $taskIds): void
    {
        foreach ($taskIds as $taskId) {
            $taskId = trim((string) $taskId);
            if ($taskId === '' || !preg_match('/^[a-f0-9]{32}$/', $taskId)) {
                continue;
            }
            $meta = $this->loadMeta($ownerUserId, $taskId);
            if ($meta === null) {
                continue;
            }
            if (!\in_array(($meta['status'] ?? ''), [self::STATUS_DONE, self::STATUS_FAILED, self::STATUS_CANCELLED], true)) {
                continue;
            }
            $meta['notified'] = true;
            $meta['updated_at'] = time();
            $this->saveMeta($ownerUserId, $taskId, $meta);
        }
    }

    /**
     * @brief Load one task as public array.
     * @param int $ownerUserId Owner id.
     * @param string $taskId Task id.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function getPublic(int $ownerUserId, string $taskId): array
    {
        $meta = $this->loadMeta($ownerUserId, $taskId);
        if ($meta === null) {
            throw new \RuntimeException('upload_encrypt.not_found');
        }

        return $this->toPublicArray($meta);
    }

    /**
     * @param array<string, mixed> $meta Job meta.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function toPublicArray(array $meta): array
    {
        $total = (int) ($meta['plain_total'] ?? 0);
        $done = (int) ($meta['plain_done'] ?? 0);
        $percent = $total > 0 ? (int) min(100, floor(($done / $total) * 100)) : 0;

        return [
            'taskId' => (string) ($meta['task_id'] ?? ''),
            'type' => (string) ($meta['type'] ?? self::TYPE),
            'status' => (string) ($meta['status'] ?? ''),
            'label' => (string) ($meta['label'] ?? ''),
            'doneBytes' => $done,
            'totalBytes' => $total,
            'percent' => $percent,
            'errorKey' => $meta['error_key'] ?? null,
            'modalKey' => (string) ($meta['modal_key'] ?? 'files_upload'),
            'sharedFileId' => $meta['shared_file_id'] ?? null,
            'notified' => (bool) ($meta['notified'] ?? false),
            'createdAt' => (int) ($meta['created_at'] ?? 0),
            'updatedAt' => (int) ($meta['updated_at'] ?? 0),
            'finishedAt' => $meta['finished_at'] ?? null,
        ];
    }

    /**
     * @param int $ownerUserId Owner.
     * @param array<string, mixed> $session Chunk session meta.
     * @return array{display_name: string, folder_id: int}
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function resolveDisplayAndFolder(int $ownerUserId, array $session): array
    {
        $displayName = trim((string) ($session['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = 'file';
        }
        $targetFolderId = (int) ($session['folder_retain_id'] ?? 0);
        $targetFolder = $this->folderTreeService->resolveCurrentFolder($ownerUserId, $targetFolderId > 0 ? $targetFolderId : null);
        if ($targetFolderId > 0 && !$targetFolder instanceof Folder) {
            throw new \RuntimeException('chunk_upload.folder_invalid');
        }

        $relativePath = trim((string) ($session['relative_path'] ?? ''));
        if ($relativePath !== '') {
            $split = $this->folderPathMaterializerService->splitRelativeFilePath($relativePath);
            $displayName = $split['file_name'];
            $folderResult = $this->folderPathMaterializerService->ensureFolderPathFromRelative(
                $ownerUserId,
                $targetFolder,
                $split['folder_path'],
                FolderPathMaterializerService::CONFLICT_ABORT
            );
            if ($folderResult === FolderPathMaterializerService::CONFLICT_ABORT) {
                throw new \RuntimeException('chunk_upload.path_invalid');
            }
            /** @var Folder|null $targetFolder */
            $targetFolder = $folderResult;
        }

        if ($this->folderPathMaterializerService->hasFileNameConflict($ownerUserId, $targetFolder, $displayName)) {
            throw new \RuntimeException('chunk_upload.name_conflict');
        }

        return [
            'display_name' => $displayName,
            'folder_id' => $targetFolder instanceof Folder ? (int) $targetFolder->getId() : 0,
        ];
    }

    /**
     * @param int $ownerUserId Owner.
     * @param array<string, mixed> $meta Meta.
     * @param string $errorKey Flash/i18n key.
     * @param \Throwable|null $cause Optional root cause.
     * @return array<string, mixed>
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function fail(int $ownerUserId, array $meta, string $errorKey, ?\Throwable $cause = null): array
    {
        $taskId = (string) ($meta['task_id'] ?? '');
        $cipherPath = (string) ($meta['cipher_path'] ?? '');
        $uploadId = (string) ($meta['upload_id'] ?? '');
        $this->uploadDiagnosticLogger->fail('upload.encrypt.failed', [
            'owner_user_id' => $ownerUserId,
            'task_id' => $taskId,
            'upload_id' => $uploadId,
            'error_key' => $errorKey,
            'plain_done' => (int) ($meta['plain_done'] ?? 0),
            'plain_total' => (int) ($meta['plain_total'] ?? 0),
            'path' => $cipherPath,
            'part_path' => (string) ($meta['part_path'] ?? ''),
        ], $cause);
        $this->cleanupCipherArtifacts($cipherPath);
        if ($uploadId !== '') {
            $this->chunkedUploadService->abortSession($ownerUserId, $uploadId);
        }
        $meta['status'] = self::STATUS_FAILED;
        $meta['error_key'] = $errorKey;
        $meta['finished_at'] = time();
        $meta['updated_at'] = time();
        $this->saveMeta($ownerUserId, $taskId, $meta);
        @unlink($this->cancelPath($ownerUserId, $taskId));

        return $this->toPublicArray($meta);
    }

    /**
     * @param string $cipherPath Cipher path.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function cleanupCipherArtifacts(string $cipherPath): void
    {
        if ($cipherPath === '') {
            return;
        }
        @unlink($cipherPath);
        @unlink($cipherPath.V2SegmentIndexService::INDEX_EXTENSION);
        @unlink($cipherPath.V2SegmentIndexService::INDEX_EXTENSION.'.tmp');
    }

    /**
     * @param int $ownerUserId Owner.
     * @param string $taskId Task.
     * @return bool
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function isCancelRequested(int $ownerUserId, string $taskId): bool
    {
        return is_file($this->cancelPath($ownerUserId, $taskId));
    }

    /**
     * @param int $ownerUserId Owner.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function purgeExpiredForOwner(int $ownerUserId): void
    {
        $dir = $this->jobsBaseDir($ownerUserId);
        if (!is_dir($dir)) {
            return;
        }
        $now = time();
        foreach (glob($dir.'/*'.self::META_SUFFIX) ?: [] as $metaFile) {
            $raw = @file_get_contents($metaFile);
            if (!is_string($raw)) {
                continue;
            }
            try {
                /** @var array<string, mixed> $m */
                $m = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            $created = (int) ($m['created_at'] ?? 0);
            $finished = (int) ($m['finished_at'] ?? 0);
            $status = (string) ($m['status'] ?? '');
            $expiredRunning = $created > 0 && ($now - $created) > self::SESSION_TTL_SECONDS && $status === self::STATUS_RUNNING;
            $expiredTerminal = $finished > 0 && ($now - $finished) > self::TERMINAL_RETENTION_SECONDS && $status !== self::STATUS_RUNNING;
            if (!$expiredRunning && !$expiredTerminal) {
                continue;
            }
            $taskId = (string) ($m['task_id'] ?? '');
            if ($status === self::STATUS_RUNNING) {
                $this->cleanupCipherArtifacts((string) ($m['cipher_path'] ?? ''));
                $uploadId = (string) ($m['upload_id'] ?? '');
                if ($uploadId !== '') {
                    $this->chunkedUploadService->abortSession($ownerUserId, $uploadId);
                }
            }
            @unlink($metaFile);
            if ($taskId !== '') {
                @unlink($this->cancelPath($ownerUserId, $taskId));
            }
        }
    }

    /**
     * @param int $ownerUserId Owner.
     * @param string $taskId Task.
     * @return array<string, mixed>|null
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function loadMeta(int $ownerUserId, string $taskId): ?array
    {
        $path = $this->metaPath($ownerUserId, $taskId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        try {
            /** @var array<string, mixed> $meta */
            $meta = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $meta;
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * @param int $ownerUserId Owner.
     * @param string $taskId Task.
     * @param array<string, mixed> $meta Meta.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function saveMeta(int $ownerUserId, string $taskId, array $meta): void
    {
        $this->ensureWritableDirectory($this->jobsBaseDir($ownerUserId));
        $written = @file_put_contents(
            $this->metaPath($ownerUserId, $taskId),
            json_encode($meta, JSON_THROW_ON_ERROR),
        );
        if ($written === false) {
            $this->throwLogged('upload.encrypt.meta_write', RuntimePermissionFailure::EXCEPTION, [
                'owner_user_id' => $ownerUserId,
                'task_id' => $taskId,
                'path' => $this->metaPath($ownerUserId, $taskId),
            ]);
        }
    }

    /**
     * @param int $ownerUserId Owner.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function jobsBaseDir(int $ownerUserId): string
    {
        return $this->projectDir.'/var/tasks/'.$ownerUserId;
    }

    /**
     * @param int $ownerUserId Owner.
     * @param string $taskId Task.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function metaPath(int $ownerUserId, string $taskId): string
    {
        return $this->jobsBaseDir($ownerUserId).'/'.$taskId.self::META_SUFFIX;
    }

    /**
     * @param int $ownerUserId Owner.
     * @param string $taskId Task.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function cancelPath(int $ownerUserId, string $taskId): string
    {
        return $this->jobsBaseDir($ownerUserId).'/'.$taskId.self::CANCEL_SUFFIX;
    }

    /**
     * @param string $dir Directory.
     * @return void
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function ensureWritableDirectory(string $dir): void
    {
        try {
            RuntimePermissionFailure::ensureWritableDirectory($dir);
        } catch (\RuntimeException $e) {
            $this->uploadDiagnosticLogger->fail('upload.encrypt.mkdir', ['path' => $dir], $e);
            throw $e;
        }
    }

    /**
     * @brief Log then throw an encrypt-job failure with a stable error code.
     * @param string $event Diagnostic event name.
     * @param string $code Exception message / error code.
     * @param array<string, mixed> $ctx Scalar context.
     * @param \Throwable|null $previous Optional cause.
     * @return never
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function throwLogged(string $event, string $code, array $ctx, ?\Throwable $previous = null): never
    {
        $exception = new \RuntimeException($code, 0, $previous);
        $this->uploadDiagnosticLogger->fail($event, $ctx + ['error_code' => $code], $exception);
        throw $exception;
    }
}
