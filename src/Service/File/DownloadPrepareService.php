<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\SharedFile;

/**
 * @brief Filesystem-backed incremental decrypt jobs for large download delivery.
 * @author Stephane H.
 * @date 2026-07-07
 */
final class DownloadPrepareService
{
    public const PHASE_PENDING = 'pending';

    public const PHASE_DECRYPTING = 'decrypting';

    public const PHASE_READY = 'ready';

    public const PHASE_FAILED = 'failed';

    public const PHASE_CANCELLED = 'cancelled';

    public const ACTOR_USER = 'user';

    public const ACTOR_PUBLIC = 'public';

    private const META_FILENAME = 'meta.json';

    private const PLAIN_FILENAME = 'plain.bin';

    public function __construct(
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly string $projectDir,
        private readonly int $tickPlainBytes,
        private readonly int $sessionTtlSeconds,
        private readonly int $maxConcurrentJobs,
        private readonly int $directMaxBytes,
    ) {
    }

    /**
     * @brief Whether a shared file should use prepared download instead of direct streaming.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return bool
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function requiresPreparedDownload(SharedFile $sharedFile): bool
    {
        return (int) $sharedFile->getByteSize() > $this->directMaxBytes;
    }

    /**
     * @brief Create or reuse a prepared download job for an authenticated user.
     * @param int $userId Authenticated user id.
     * @param SharedFile $sharedFile Authorized shared file.
     * @return array{job_id: string, phase: string, bytes: int, plain_written: int, file_name: string, reused: bool}
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function createAuthenticatedJob(int $userId, SharedFile $sharedFile): array
    {
        $namespace = $this->userNamespace($userId);
        $this->purgeExpiredForNamespace($namespace);
        $this->assertCanCreateJob($namespace, $sharedFile);

        $existing = $this->findReusableJob($namespace, self::ACTOR_USER, $userId, $sharedFile);
        if ($existing !== null) {
            return $this->buildCreatePayload($existing, true);
        }

        return $this->buildCreatePayload($this->createJob(
            $namespace,
            $sharedFile,
            self::ACTOR_USER,
            $userId,
            null,
            null,
        ), false);
    }

    /**
     * @brief Create or reuse a prepared download job for a verified public challenge.
     * @param int $challengeId Verified public challenge id.
     * @param string $publicToken Public share token.
     * @param SharedFile $sharedFile Public shared file.
     * @return array{job_id: string, phase: string, bytes: int, plain_written: int, file_name: string, reused: bool}
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function createPublicJob(int $challengeId, string $publicToken, SharedFile $sharedFile): array
    {
        $namespace = $this->publicNamespace($challengeId);
        $this->purgeExpiredForNamespace($namespace);
        $this->assertCanCreateJob($namespace, $sharedFile);

        $existing = $this->findReusableJob($namespace, self::ACTOR_PUBLIC, $challengeId, $sharedFile);
        if ($existing !== null) {
            return $this->buildCreatePayload($existing, true);
        }

        return $this->buildCreatePayload($this->createJob(
            $namespace,
            $sharedFile,
            self::ACTOR_PUBLIC,
            $challengeId,
            $publicToken,
            $challengeId,
        ), false);
    }

    /**
     * @brief Advance one decrypt tick for a job.
     * @param string $namespace Job namespace.
     * @param string $jobId Job identifier.
     * @param string $actorKind Expected actor kind.
     * @param int $actorId Expected actor id.
     * @return array{job_id: string, phase: string, bytes: int, plain_written: int, percent: int, complete: bool, file_name: string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function tickJob(string $namespace, string $jobId, string $actorKind, int $actorId): array
    {
        $meta = $this->loadMeta($namespace, $jobId);
        if ($meta === null) {
            throw new \RuntimeException('download.prepare.job_not_found');
        }
        $this->assertActorMatches($meta, $actorKind, $actorId);

        $phase = (string) ($meta['phase'] ?? self::PHASE_PENDING);
        if ($phase === self::PHASE_READY) {
            return $this->buildProgressPayload($meta, true);
        }
        if ($phase === self::PHASE_FAILED || $phase === self::PHASE_CANCELLED) {
            throw new \RuntimeException('download.prepare.job_not_found');
        }

        $this->assertStorageStillValid($meta);

        if ($phase === self::PHASE_PENDING) {
            $meta['phase'] = self::PHASE_DECRYPTING;
            $meta['cipher_offset'] = FileEncryptionService::V2_CIPHER_BODY_START_OFFSET;
            $meta['plain_written'] = 0;
            $this->saveMeta($namespace, $jobId, $meta);
        }

        $plainPath = $this->plainPath($namespace, $jobId);
        $out = fopen($plainPath, file_exists($plainPath) ? 'ab' : 'wb');
        if ($out === false) {
            $this->failJob($namespace, $jobId, $meta, 'download.prepare.storage_failed');
            throw new \RuntimeException('download.prepare.storage_failed');
        }

        try {
            $result = $this->fileEncryptionService->streamDecryptStorageContinueToHandle(
                (string) ($meta['storage_path'] ?? ''),
                (int) ($meta['cipher_offset'] ?? FileEncryptionService::V2_CIPHER_BODY_START_OFFSET),
                $this->tickPlainBytes,
                $out,
            );
        } catch (\RuntimeException) {
            $this->failJob($namespace, $jobId, $meta, 'download.prepare.decrypt_failed');
            throw new \RuntimeException('download.prepare.decrypt_failed');
        } finally {
            fclose($out);
        }

        $plainWritten = (int) ($meta['plain_written'] ?? 0) + (int) ($result['plainWritten'] ?? 0);
        $totalPlainBytes = (int) ($meta['total_plain_bytes'] ?? 0);
        $meta['plain_written'] = $plainWritten;
        $meta['cipher_offset'] = (int) ($result['nextCipherOffset'] ?? 0);

        if ($plainWritten >= $totalPlainBytes) {
            $meta['phase'] = self::PHASE_READY;
            $meta['ready_at'] = time();
        } else {
            $meta['phase'] = self::PHASE_DECRYPTING;
        }

        $this->saveMeta($namespace, $jobId, $meta);

        return $this->buildProgressPayload($meta, $meta['phase'] === self::PHASE_READY);
    }

    /**
     * @brief Cancel a job and delete temporary plaintext artifacts.
     * @param string $namespace Job namespace.
     * @param string $jobId Job identifier.
     * @param string $actorKind Expected actor kind.
     * @param int $actorId Expected actor id.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function cancelJob(string $namespace, string $jobId, string $actorKind, int $actorId): void
    {
        $meta = $this->loadMeta($namespace, $jobId);
        if ($meta === null) {
            throw new \RuntimeException('download.prepare.job_not_found');
        }
        $this->assertActorMatches($meta, $actorKind, $actorId);
        $meta['phase'] = self::PHASE_CANCELLED;
        $this->saveMeta($namespace, $jobId, $meta);
        $this->cleanupJobFiles($namespace, $jobId);
    }

    /**
     * @brief Resolve deliverable plaintext path when a job is ready.
     * @param string $namespace Job namespace.
     * @param string $jobId Job identifier.
     * @param string $actorKind Expected actor kind.
     * @param int $actorId Expected actor id.
     * @return array{plain_path: string, file_name: string, mime_type: string, bytes: int}
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function resolveReadyDelivery(string $namespace, string $jobId, string $actorKind, int $actorId): array
    {
        $meta = $this->loadMeta($namespace, $jobId);
        if ($meta === null || (string) ($meta['phase'] ?? '') !== self::PHASE_READY) {
            throw new \RuntimeException('download.prepare.not_ready');
        }
        $this->assertActorMatches($meta, $actorKind, $actorId);
        $this->assertStorageStillValid($meta);

        $plainPath = $this->plainPath($namespace, $jobId);
        if (!is_readable($plainPath)) {
            throw new \RuntimeException('download.prepare.not_ready');
        }

        return [
            'plain_path' => $plainPath,
            'file_name' => (string) ($meta['file_name'] ?? 'download.bin'),
            'mime_type' => (string) ($meta['mime_type'] ?? 'application/octet-stream'),
            'bytes' => (int) ($meta['total_plain_bytes'] ?? 0),
        ];
    }

    /**
     * @brief Finalize a delivered job by removing metadata while plain.bin is deleted by the HTTP response.
     * @param string $namespace Job namespace.
     * @param string $jobId Job identifier.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function finalizeDelivery(string $namespace, string $jobId): void
    {
        @unlink($this->metaPath($namespace, $jobId));
        @rmdir($this->jobDir($namespace, $jobId));
    }

    /**
     * @brief Mark a ready job as delivered and optionally remove plaintext artifacts.
     * @param string $namespace Job namespace.
     * @param string $jobId Job identifier.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function markDelivered(string $namespace, string $jobId): void
    {
        $this->finalizeDelivery($namespace, $jobId);
    }

    /**
     * @brief Build namespace key for authenticated jobs.
     * @param int $userId User id.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function userNamespace(int $userId): string
    {
        return 'u'.$userId;
    }

    /**
     * @brief Build namespace key for public challenge jobs.
     * @param int $challengeId Challenge id.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function publicNamespace(int $challengeId): string
    {
        return 'p'.$challengeId;
    }

    /**
     * @brief Purge expired jobs across all namespaces.
     * @return int Number of purged jobs.
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function purgeExpiredAll(): int
    {
        $root = $this->prepareRootDir();
        if (!is_dir($root)) {
            return 0;
        }

        $purged = 0;
        $namespaces = scandir($root);
        if (!is_array($namespaces)) {
            return 0;
        }

        foreach ($namespaces as $namespace) {
            if ($namespace === '.' || $namespace === '..') {
                continue;
            }
            $purged += $this->purgeExpiredForNamespace($namespace);
        }

        return $purged;
    }

    /**
     * @brief Map runtime exception message keys to UI flash keys.
     * @param \RuntimeException $exception Thrown service exception.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public static function mapExceptionToFlashKey(\RuntimeException $exception): string
    {
        return match ($exception->getMessage()) {
            'download.prepare.legacy_format_unsupported' => 'files.flash.download_legacy_unsupported',
            'download.prepare.insufficient_disk' => 'files.flash.download_disk_full',
            'download.prepare.concurrent_limit' => 'files.flash.download_prepare_busy',
            'download.prepare.not_ready' => 'files.flash.download_not_ready',
            'download.prepare.job_not_found' => 'files.flash.download_prepare_not_found',
            'download.prepare.decrypt_failed', 'download.prepare.storage_failed' => 'files.flash.download_failed',
            default => 'files.flash.download_failed',
        };
    }

    /**
     * @param string $namespace Job namespace.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @param string $actorKind Actor kind constant.
     * @param int $actorId Actor id.
     * @param string|null $publicToken Public token for public actor.
     * @param int|null $challengeId Challenge id for public actor.
     * @return array<string, mixed>
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function createJob(
        string $namespace,
        SharedFile $sharedFile,
        string $actorKind,
        int $actorId,
        ?string $publicToken,
        ?int $challengeId,
    ): array {
        $storagePath = $sharedFile->getStoragePath();
        if ($storagePath === '' || !is_readable($storagePath)) {
            throw new \RuntimeException('download.prepare.storage_failed');
        }
        if (!$this->fileEncryptionService->isV2StorageFormat($storagePath)) {
            throw new \RuntimeException('download.prepare.legacy_format_unsupported');
        }

        $totalPlainBytes = $this->fileEncryptionService->readV2PlainTotalFromStorage($storagePath);
        $this->assertDiskSpaceForPlainBytes($totalPlainBytes);

        $jobId = bin2hex(random_bytes(16));
        $this->ensureNamespaceDir($namespace);
        $jobDir = $this->jobDir($namespace, $jobId);
        if (!is_dir($jobDir) && !mkdir($jobDir, 0775, true) && !is_dir($jobDir)) {
            throw new \RuntimeException('download.prepare.storage_failed');
        }

        $meta = [
            'job_id' => $jobId,
            'namespace' => $namespace,
            'created_at' => time(),
            'phase' => self::PHASE_PENDING,
            'actor_kind' => $actorKind,
            'actor_id' => $actorId,
            'challenge_id' => $challengeId,
            'public_token' => $publicToken,
            'shared_file_id' => (int) ($sharedFile->getId() ?? 0),
            'storage_path' => $storagePath,
            'storage_fingerprint' => $this->storageFingerprint($storagePath),
            'total_plain_bytes' => $totalPlainBytes,
            'plain_written' => 0,
            'cipher_offset' => FileEncryptionService::V2_CIPHER_BODY_START_OFFSET,
            'file_name' => $sharedFile->getOriginalFileName(),
            'mime_type' => $this->guessMimeTypeFromExtension($sharedFile),
        ];
        $this->saveMeta($namespace, $jobId, $meta);

        return $meta;
    }

    /**
     * @param string $namespace Job namespace.
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function assertCanCreateJob(string $namespace, SharedFile $sharedFile): void
    {
        if (!$this->requiresPreparedDownload($sharedFile)) {
            throw new \RuntimeException('download.prepare.direct_only');
        }

        $active = 0;
        $dir = $this->namespaceDir($namespace);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $meta = $this->loadMeta($namespace, $entry);
            if ($meta === null) {
                continue;
            }
            $phase = (string) ($meta['phase'] ?? '');
            if (\in_array($phase, [self::PHASE_PENDING, self::PHASE_DECRYPTING, self::PHASE_READY], true)) {
                ++$active;
            }
        }
        if ($active >= $this->maxConcurrentJobs) {
            throw new \RuntimeException('download.prepare.concurrent_limit');
        }
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $actorKind Actor kind.
     * @param int $actorId Actor id.
     * @param SharedFile $sharedFile Shared file.
     * @return array<string, mixed>|null
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function findReusableJob(string $namespace, string $actorKind, int $actorId, SharedFile $sharedFile): ?array
    {
        $dir = $this->namespaceDir($namespace);
        if (!is_dir($dir)) {
            return null;
        }

        $fingerprint = $this->storageFingerprint($sharedFile->getStoragePath());
        $fileId = (int) ($sharedFile->getId() ?? 0);

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $meta = $this->loadMeta($namespace, $entry);
            if ($meta === null) {
                continue;
            }
            if ((string) ($meta['actor_kind'] ?? '') !== $actorKind) {
                continue;
            }
            if ((int) ($meta['actor_id'] ?? 0) !== $actorId) {
                continue;
            }
            if ((int) ($meta['shared_file_id'] ?? 0) !== $fileId) {
                continue;
            }
            if ((string) ($meta['storage_fingerprint'] ?? '') !== $fingerprint) {
                continue;
            }
            $phase = (string) ($meta['phase'] ?? '');
            if (!\in_array($phase, [self::PHASE_PENDING, self::PHASE_DECRYPTING, self::PHASE_READY], true)) {
                continue;
            }

            return $meta;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $meta Job metadata.
     * @param bool $reused Whether an existing job was reused.
     * @return array{job_id: string, phase: string, bytes: int, plain_written: int, file_name: string, reused: bool}
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function buildCreatePayload(array $meta, bool $reused): array
    {
        return [
            'job_id' => (string) ($meta['job_id'] ?? ''),
            'phase' => (string) ($meta['phase'] ?? self::PHASE_PENDING),
            'bytes' => (int) ($meta['total_plain_bytes'] ?? 0),
            'plain_written' => (int) ($meta['plain_written'] ?? 0),
            'file_name' => (string) ($meta['file_name'] ?? 'download.bin'),
            'reused' => $reused,
        ];
    }

    /**
     * @param array<string, mixed> $meta Job metadata.
     * @param bool $complete Whether the job is complete.
     * @return array{job_id: string, phase: string, bytes: int, plain_written: int, percent: int, complete: bool, file_name: string}
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function buildProgressPayload(array $meta, bool $complete): array
    {
        $bytes = max(1, (int) ($meta['total_plain_bytes'] ?? 1));
        $plainWritten = (int) ($meta['plain_written'] ?? 0);

        return [
            'job_id' => (string) ($meta['job_id'] ?? ''),
            'phase' => (string) ($meta['phase'] ?? self::PHASE_PENDING),
            'bytes' => (int) ($meta['total_plain_bytes'] ?? 0),
            'plain_written' => $plainWritten,
            'percent' => min(100, (int) floor(($plainWritten / $bytes) * 100)),
            'complete' => $complete,
            'file_name' => (string) ($meta['file_name'] ?? 'download.bin'),
        ];
    }

    /**
     * @param array<string, mixed> $meta Job metadata.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function assertStorageStillValid(array $meta): void
    {
        $storagePath = (string) ($meta['storage_path'] ?? '');
        if ($storagePath === '' || !is_readable($storagePath)) {
            throw new \RuntimeException('download.prepare.storage_failed');
        }
        if ($this->storageFingerprint($storagePath) !== (string) ($meta['storage_fingerprint'] ?? '')) {
            throw new \RuntimeException('download.prepare.source_changed');
        }
    }

    /**
     * @param array<string, mixed> $meta Job metadata.
     * @param string $actorKind Actor kind.
     * @param int $actorId Actor id.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function assertActorMatches(array $meta, string $actorKind, int $actorId): void
    {
        if ((string) ($meta['actor_kind'] ?? '') !== $actorKind || (int) ($meta['actor_id'] ?? 0) !== $actorId) {
            throw new \RuntimeException('download.prepare.job_not_found');
        }
    }

    /**
     * @param int $plainBytes Required plaintext bytes.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function assertDiskSpaceForPlainBytes(int $plainBytes): void
    {
        $probe = $this->prepareRootDir();
        $free = @disk_free_space($probe);
        if ($free === false || $free < ($plainBytes * 2)) {
            throw new \RuntimeException('download.prepare.insufficient_disk');
        }
    }

    /**
     * @param string $storagePath Storage path.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function storageFingerprint(string $storagePath): string
    {
        $mtime = @filemtime($storagePath);
        $size = @filesize($storagePath);

        return hash('sha256', $storagePath.'|'.(string) $mtime.'|'.(string) $size);
    }

    /**
     * @param SharedFile $sharedFile Shared file aggregate.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function guessMimeTypeFromExtension(SharedFile $sharedFile): string
    {
        $ext = strtolower($sharedFile->getFileExtension());
        $map = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'txt' => 'text/plain; charset=utf-8',
        ];

        return $map[$ext] ?? 'application/octet-stream';
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job metadata.
     * @param string $message Failure message key.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function failJob(string $namespace, string $jobId, array $meta, string $message): void
    {
        $meta['phase'] = self::PHASE_FAILED;
        $meta['error_message'] = $message;
        $this->saveMeta($namespace, $jobId, $meta);
        $this->cleanupJobFiles($namespace, $jobId);
    }

    /**
     * @param string $namespace Job namespace.
     * @return int
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function purgeExpiredForNamespace(string $namespace): int
    {
        $dir = $this->namespaceDir($namespace);
        if (!is_dir($dir)) {
            return 0;
        }

        $purged = 0;
        $now = time();
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $meta = $this->loadMeta($namespace, $entry);
            if ($meta === null) {
                continue;
            }
            $created = (int) ($meta['created_at'] ?? 0);
            $deliveredAt = (int) ($meta['delivered_at'] ?? 0);
            if ($deliveredAt > 0) {
                $this->cleanupJobFiles($namespace, $entry);
                ++$purged;
                continue;
            }
            if ($created > 0 && ($now - $created) > $this->sessionTtlSeconds) {
                $this->cleanupJobFiles($namespace, $entry);
                ++$purged;
            }
        }

        return $purged;
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function cleanupJobFiles(string $namespace, string $jobId): void
    {
        @unlink($this->metaPath($namespace, $jobId));
        @unlink($this->plainPath($namespace, $jobId));
        @rmdir($this->jobDir($namespace, $jobId));
    }

    /**
     * @param string $namespace Job namespace.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function ensureNamespaceDir(string $namespace): void
    {
        $dir = $this->namespaceDir($namespace);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('download.prepare.storage_failed');
        }
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @return array<string, mixed>|null
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function loadMeta(string $namespace, string $jobId): ?array
    {
        $path = $this->metaPath($namespace, $jobId);
        if (!is_readable($path)) {
            return null;
        }
        $raw = file_get_contents($path);
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
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @param array<string, mixed> $meta Job metadata.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function saveMeta(string $namespace, string $jobId, array $meta): void
    {
        file_put_contents($this->metaPath($namespace, $jobId), json_encode($meta, JSON_THROW_ON_ERROR));
    }

    /**
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function prepareRootDir(): string
    {
        return $this->projectDir.'/var/download_prepare';
    }

    /**
     * @param string $namespace Job namespace.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function namespaceDir(string $namespace): string
    {
        return $this->prepareRootDir().'/'.$namespace;
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function jobDir(string $namespace, string $jobId): string
    {
        return $this->namespaceDir($namespace).'/'.$jobId;
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function metaPath(string $namespace, string $jobId): string
    {
        return $this->jobDir($namespace, $jobId).'/'.self::META_FILENAME;
    }

    /**
     * @param string $namespace Job namespace.
     * @param string $jobId Job id.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    private function plainPath(string $namespace, string $jobId): string
    {
        return $this->jobDir($namespace, $jobId).'/'.self::PLAIN_FILENAME;
    }
}
