<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Folder;
use App\Service\Admin\ServerDiskSpaceService;
use App\Service\Audit\UploadDiagnosticLogger;
use App\Service\Infrastructure\RuntimePermissionFailure;
use App\Service\Share\FolderTreeService;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * @brief Resumable filesystem-backed assembly for large multipart chunk uploads.
 * @author Stephane H.
 * @date 2026-05-03
 */
final class ChunkedUploadService
{
    private const META_SUFFIX = '.json';

    private const PART_SUFFIX = '.part';

    private const SESSION_TTL_SECONDS = 86400;

    public function __construct(
        private readonly FolderTreeService $folderTreeService,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly ServerDiskSpaceService $serverDiskSpaceService,
        private readonly UploadDiagnosticLogger $uploadDiagnosticLogger,
        private readonly string $projectDir,
        private readonly int $chunkBytesDefault,
        private readonly int $chunkBytesMin = 262144,
        private readonly int $chunkTimeBudgetMs = 25000,
    ) {
    }

    /**
     * @brief Create a new chunk session and empty assembly file.
     * @param int $ownerUserId Owner user id.
     * @param int $expectedBytes Total plaintext bytes declared by client.
     * @param string $displayName Original filename.
     * @param int $folderRetainId Retained folder id from listing (0 root).
     * @param string|null $relativePath Optional client-relative path for folder tree uploads.
     * @return array{
     *     upload_id: string,
     *     chunk_size_bytes: int,
     *     chunk_size_min_bytes: int,
     *     chunk_time_budget_ms: int,
     *     temp_max_bytes: int
     * }
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function createSession(
        int $ownerUserId,
        int $expectedBytes,
        string $displayName,
        int $folderRetainId,
        ?string $relativePath = null,
    ): array {
        $this->purgeExpiredForOwner($ownerUserId);
        $sessionCtx = [
            'owner_user_id' => $ownerUserId,
            'expected_bytes' => $expectedBytes,
            'folder_retain_id' => $folderRetainId,
        ];
        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, $expectedBytes);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                $this->throwLogged('upload.session.quota', 'chunk_upload.quota_exceeded', $sessionCtx, $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.session.quota', $sessionCtx, $e);
            throw $e;
        }
        try {
            $this->serverDiskSpaceService->assertFreeBytesForUploadEncryptPeak($expectedBytes);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === ServerDiskSpaceService::EXCEPTION_DISK_FULL) {
                $this->throwLogged('upload.session.disk', 'chunk_upload.disk_full', $sessionCtx, $e);
            }
            $this->uploadDiagnosticLogger->fail('upload.session.disk', $sessionCtx, $e);
            throw $e;
        }
        if ($folderRetainId > 0 && !$this->folderTreeService->resolveCurrentFolder($ownerUserId, $folderRetainId) instanceof Folder) {
            $this->throwLogged('upload.session.folder', 'chunk_upload.folder_invalid', $sessionCtx);
        }
        $sanitizedRelativePath = $this->sanitizeRelativePath($relativePath);
        if ($relativePath !== null && $relativePath !== '' && $sanitizedRelativePath === null) {
            $this->throwLogged('upload.session.path', 'chunk_upload.path_invalid', $sessionCtx);
        }

        $uploadId = bin2hex(random_bytes(16));
        $this->ensureWritableDirectory($this->chunkUploadRootDir());
        $baseDir = $this->sessionBaseDir($ownerUserId);
        $this->ensureWritableDirectory($baseDir);

        $partPath = $this->partPath($ownerUserId, $uploadId);
        $metaPath = $this->metaPath($ownerUserId, $uploadId);
        $sessionCtx['upload_id'] = $uploadId;

        if (@file_put_contents($partPath, '') === false) {
            $this->throwLogged('upload.session.part_init', RuntimePermissionFailure::EXCEPTION, $sessionCtx + ['path' => $partPath]);
        }

        $meta = [
            'owner_user_id' => $ownerUserId,
            'expected_bytes' => $expectedBytes,
            'received_bytes' => 0,
            'display_name' => $displayName,
            'folder_retain_id' => $folderRetainId,
            'created_at' => time(),
            'next_chunk_index' => 0,
        ];
        if ($sanitizedRelativePath !== null && $sanitizedRelativePath !== '') {
            $meta['relative_path'] = $sanitizedRelativePath;
        }
        if (@file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR)) === false) {
            @unlink($partPath);
            $this->throwLogged('upload.session.meta_write', RuntimePermissionFailure::EXCEPTION, $sessionCtx + ['path' => $metaPath]);
        }

        $chunkSize = $this->resolveAdvertisedChunkBytes();
        $chunkMin = $this->resolveMinChunkBytes($chunkSize);
        $this->uploadDiagnosticLogger->info('upload.session.created', $sessionCtx + [
            'path' => $partPath,
            'chunk_size_bytes' => $chunkSize,
            'chunk_size_min_bytes' => $chunkMin,
            'chunk_time_budget_ms' => max(5000, $this->chunkTimeBudgetMs),
        ]);

        return [
            'upload_id' => $uploadId,
            'chunk_size_bytes' => $chunkSize,
            'chunk_size_min_bytes' => $chunkMin,
            'chunk_time_budget_ms' => max(5000, $this->chunkTimeBudgetMs),
            'temp_max_bytes' => $expectedBytes,
        ];
    }

    /**
     * @brief Append one chunk in order (next_chunk_index).
     * @param string $uploadId Session identifier.
     * @param int $ownerUserId Owner user id.
     * @param int $chunkIndex Zero-based chunk index (must match server expectation).
     * @param UploadedFile $chunk Uploaded chunk body.
     * @return array{received_bytes: int, complete: bool}
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function appendChunk(string $uploadId, int $ownerUserId, int $chunkIndex, UploadedFile $chunk): array
    {
        $chunkCtx = [
            'owner_user_id' => $ownerUserId,
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
        ];
        if (!$chunk->isValid()) {
            $this->throwLogged('upload.chunk.invalid', 'chunk_upload.chunk_invalid', $chunkCtx + [
                'php_upload_error' => $chunk->getError(),
            ]);
        }

        $meta = $this->loadMeta($ownerUserId, $uploadId);
        if ($meta === null) {
            $this->throwLogged('upload.chunk.session', 'chunk_upload.session_not_found', $chunkCtx + [
                'path' => $this->metaPath($ownerUserId, $uploadId),
            ]);
        }

        $expectedIndex = (int) ($meta['next_chunk_index'] ?? 0);
        $received = (int) ($meta['received_bytes'] ?? 0);
        $expectedTotal = (int) ($meta['expected_bytes'] ?? 0);
        // Idempotent retry: gateway may return 504 after the chunk was already persisted.
        if ($chunkIndex < $expectedIndex) {
            return [
                'received_bytes' => $received,
                'complete' => $received >= $expectedTotal && $expectedTotal >= 0,
            ];
        }
        if ($chunkIndex !== $expectedIndex) {
            $this->throwLogged('upload.chunk.order', 'chunk_upload.chunk_order', $chunkCtx + [
                'expected_index' => $expectedIndex,
            ]);
        }

        $partPath = $this->partPath($ownerUserId, $uploadId);
        $append = @file_get_contents($chunk->getRealPath());
        if (!is_string($append)) {
            $this->throwLogged('upload.chunk.read', 'chunk_upload.chunk_read_failed', $chunkCtx + [
                'path' => (string) $chunk->getRealPath(),
            ]);
        }

        $len = strlen($append);
        $chunkCtx['received_bytes'] = $received;
        $chunkCtx['expected_bytes'] = $expectedTotal;
        $chunkCtx['chunk_bytes'] = $len;
        if ($received + $len > $expectedTotal) {
            $this->throwLogged('upload.chunk.overflow', 'chunk_upload.size_overflow', $chunkCtx);
        }

        $out = @fopen($partPath, 'ab');
        if ($out === false) {
            $this->throwLogged('upload.chunk.append', RuntimePermissionFailure::EXCEPTION, $chunkCtx + [
                'path' => $partPath,
            ]);
        }
        try {
            $written = fwrite($out, $append);
            if ($written === false || $written !== $len) {
                $last = error_get_last();
                $lastMessage = is_array($last) ? (string) ($last['message'] ?? '') : '';
                $code = RuntimePermissionFailure::matches(new \RuntimeException($lastMessage))
                    ? RuntimePermissionFailure::EXCEPTION
                    : 'chunk_upload.append_failed';
                $this->throwLogged('upload.chunk.append', $code, $chunkCtx + [
                    'path' => $partPath,
                    'written_bytes' => is_int($written) ? $written : -1,
                ]);
            }
        } finally {
            fclose($out);
        }

        $received += $len;
        $meta['received_bytes'] = $received;
        $meta['next_chunk_index'] = $expectedIndex + 1;
        $this->saveMeta($ownerUserId, $uploadId, $meta);

        $complete = $received >= $expectedTotal && $expectedTotal >= 0;

        return [
            'received_bytes' => $received,
            'complete' => $complete,
        ];
    }

    /**
     * @brief Log a client-observed gateway timeout once per upload/chunk/attempt.
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Upload session id.
     * @param int $chunkIndex Chunk index that timed out.
     * @param int $chunkBytes Attempted chunk payload size.
     * @param int $durationMs Client-measured request duration.
     * @param int $httpStatus HTTP status observed by the client (504/502/503/0).
     * @param int $attempt Zero-based retry attempt.
     * @return bool True when a new warning was written.
     * @date 2026-11-10
     * @author Stephane H.
     */
    public function reportClientGatewayTimeout(
        int $ownerUserId,
        string $uploadId,
        int $chunkIndex,
        int $chunkBytes,
        int $durationMs,
        int $httpStatus,
        int $attempt,
    ): bool {
        if (!preg_match('/^[a-f0-9]{32}$/', $uploadId)) {
            return false;
        }
        if ($this->loadMeta($ownerUserId, $uploadId) === null) {
            return false;
        }
        $safeIndex = max(0, $chunkIndex);
        $safeAttempt = max(0, $attempt);
        $markerPath = $this->gatewayTimeoutMarkerPath($ownerUserId, $uploadId, $safeIndex, $safeAttempt);
        if (is_file($markerPath)) {
            return false;
        }
        if (@file_put_contents($markerPath, '1') === false) {
            return false;
        }
        $this->uploadDiagnosticLogger->warn('upload.chunk.gateway_timeout', [
            'owner_user_id' => $ownerUserId,
            'upload_id' => $uploadId,
            'chunk_index' => $safeIndex,
            'chunk_bytes' => max(0, $chunkBytes),
            'duration_ms' => max(0, $durationMs),
            'http_status' => $httpStatus,
            'attempt' => $safeAttempt,
            'path' => $markerPath,
        ]);

        return true;
    }

    /**
     * @brief Remove session files if present (after error).
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Upload id.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    public function abortSession(int $ownerUserId, string $uploadId): void
    {
        $meta = $this->loadMeta($ownerUserId, $uploadId);
        @unlink($this->partPath($ownerUserId, $uploadId));
        @unlink($this->metaPath($ownerUserId, $uploadId));
        if (\is_array($meta)) {
            $globbed = glob($this->sessionBaseDir($ownerUserId).'/'.$uploadId.'.gw.*');
            if (\is_array($globbed)) {
                foreach ($globbed as $marker) {
                    @unlink($marker);
                }
            }
        }
    }

    /**
     * @brief Abort every chunk session for an owner (OPS purge-all).
     * @param int $ownerUserId Owner id.
     * @return int Number of sessions removed.
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function abortAllSessionsForOwner(int $ownerUserId): int
    {
        $dir = $this->sessionBaseDir($ownerUserId);
        if (!is_dir($dir)) {
            return 0;
        }
        $removed = 0;
        $globbed = glob($dir.'/*.json');
        $metaFiles = \is_array($globbed) ? $globbed : [];
        foreach ($metaFiles as $metaFile) {
            $uploadId = basename($metaFile, self::META_SUFFIX);
            if ($uploadId === '' || $uploadId === basename($metaFile)) {
                continue;
            }
            $this->abortSession($ownerUserId, $uploadId);
            ++$removed;
        }
        $parts = glob($dir.'/*'.self::PART_SUFFIX);
        if (\is_array($parts)) {
            foreach ($parts as $partFile) {
                @unlink($partFile);
            }
        }

        return $removed;
    }

    /**
     * @param int $ownerUserId Owner id.
     * @return void
     * @date 2026-05-03
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
                    $base = substr($metaFile, 0, -strlen(self::META_SUFFIX));
                    @unlink($metaFile);
                    @unlink($base.self::PART_SUFFIX);
                }
            } catch (\JsonException) {
                continue;
            }
        }
    }

    /**
     * @brief Public accessor for chunk session meta (encrypt job bootstrap).
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Upload session id.
     * @return array<string, mixed>|null
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function loadSessionMeta(int $ownerUserId, string $uploadId): ?array
    {
        return $this->loadMeta($ownerUserId, $uploadId);
    }

    /**
     * @brief Absolute path of the assembled plaintext part file.
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Upload session id.
     * @return string
     * @date 2026-11-09
     * @author Stephane H.
     */
    public function resolvePartPath(int $ownerUserId, string $uploadId): string
    {
        return $this->partPath($ownerUserId, $uploadId);
    }

    /**
     * @return array<string, mixed>|null
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function loadMeta(int $ownerUserId, string $uploadId): ?array
    {
        $path = $this->metaPath($ownerUserId, $uploadId);
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
     * @param array<string, mixed> $meta Meta payload.
     * @return void
     * @date 2026-05-03
     * @author Stephane H.
     */
    private function saveMeta(int $ownerUserId, string $uploadId, array $meta): void
    {
        $path = $this->metaPath($ownerUserId, $uploadId);
        $written = @file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
        if ($written === false) {
            $this->throwLogged('upload.session.meta_write', RuntimePermissionFailure::EXCEPTION, [
                'owner_user_id' => $ownerUserId,
                'upload_id' => $uploadId,
                'path' => $path,
            ]);
        }
    }

    private function chunkUploadRootDir(): string
    {
        return $this->projectDir.'/var/chunk_upload';
    }

    /**
     * @brief Create one directory when missing without surfacing PHP warnings to the HTTP layer.
     * @param string $absoluteDir Absolute directory path.
     * @return void
     * @throws \RuntimeException When the directory cannot be created or is not writable.
     * @date 2026-06-24
     * @author Stephane H.
     */
    private function ensureWritableDirectory(string $absoluteDir): void
    {
        try {
            RuntimePermissionFailure::ensureWritableDirectory($absoluteDir);
        } catch (\RuntimeException $e) {
            $this->uploadDiagnosticLogger->fail('upload.mkdir', ['path' => $absoluteDir], $e);
            throw $e;
        }
    }

    /**
     * @brief Chunk size advertised to the client, clamped under PHP upload/post caps.
     * @return int
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function resolveAdvertisedChunkBytes(): int
    {
        $limits = [max(1024, $this->chunkBytesDefault)];
        $uploadMax = $this->parseIniSizeToBytes((string) ini_get('upload_max_filesize'));
        $postMax = $this->parseIniSizeToBytes((string) ini_get('post_max_size'));
        // Leave headroom for multipart FormData fields around the binary part.
        if ($uploadMax > 0) {
            $limits[] = max(1024, $uploadMax - 65536);
        }
        if ($postMax > 0) {
            $limits[] = max(1024, $postMax - 262144);
        }

        return max(1024, min($limits));
    }

    /**
     * @brief Adaptive floor for client chunk shrinking (never above the advertised max).
     * @param int $advertisedMax Advertised max chunk size.
     * @return int
     * @date 2026-11-10
     * @author Stephane H.
     */
    private function resolveMinChunkBytes(int $advertisedMax): int
    {
        $min = max(1024, $this->chunkBytesMin);

        return min($min, max(1024, $advertisedMax));
    }

    /**
     * @brief Parse a PHP ini size string (e.g. 8M) into bytes.
     * @param string $value Ini size value.
     * @return int
     * @date 2026-11-09
     * @author Stephane H.
     */
    private function parseIniSizeToBytes(string $value): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === '0') {
            return 0;
        }
        if (!preg_match('/^(\d+)([KMG]?)$/i', $trimmed, $matches)) {
            return 0;
        }
        $n = (int) $matches[1];
        $unit = strtoupper($matches[2] ?? '');

        return match ($unit) {
            'G' => $n * 1024 * 1024 * 1024,
            'M' => $n * 1024 * 1024,
            'K' => $n * 1024,
            default => $n,
        };
    }

    /**
     * @brief Log then throw a chunk-upload failure with a stable error code.
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

    private function sessionBaseDir(int $ownerUserId): string
    {
        return $this->chunkUploadRootDir().'/'.$ownerUserId;
    }

    private function partPath(int $ownerUserId, string $uploadId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$uploadId.self::PART_SUFFIX;
    }

    private function metaPath(int $ownerUserId, string $uploadId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$uploadId.self::META_SUFFIX;
    }

    /**
     * @brief Marker path used to dedupe client gateway-timeout logs.
     * @param int $ownerUserId Owner id.
     * @param string $uploadId Upload id.
     * @param int $chunkIndex Chunk index.
     * @param int $attempt Attempt index.
     * @return string
     * @date 2026-11-10
     * @author Stephane H.
     */
    private function gatewayTimeoutMarkerPath(int $ownerUserId, string $uploadId, int $chunkIndex, int $attempt): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$uploadId.'.gw.'.$chunkIndex.'.'.$attempt;
    }

    /**
     * @brief Normalize and validate a client relative upload path.
     * @param string|null $relativePath Raw relative path from the client.
     * @return string|null Sanitized path, empty string when absent, null when invalid.
     * @date 2026-06-25
     * @author Stephane H.
     */
    private function sanitizeRelativePath(?string $relativePath): ?string
    {
        if ($relativePath === null) {
            return '';
        }
        $relativePath = trim(str_replace('\\', '/', $relativePath));
        if ($relativePath === '') {
            return '';
        }
        if (str_starts_with($relativePath, '/')) {
            return null;
        }
        $segments = explode('/', $relativePath);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                return null;
            }
        }

        return $relativePath;
    }
}
