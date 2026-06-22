<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Folder;
use App\Entity\SharedFile;
use App\Repository\FolderRepository;
use App\Repository\SharedFileRepository;
use App\Service\Share\FolderTreeService;
use Doctrine\ORM\EntityManagerInterface;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly FolderTreeService $folderTreeService,
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly UserStorageQuotaService $userStorageQuotaService,
        private readonly string $projectDir,
        private readonly int $chunkBytesDefault,
    ) {
    }

    /**
     * @brief Create a new chunk session and empty assembly file.
     * @param int $ownerUserId Owner user id.
     * @param int $expectedBytes Total plaintext bytes declared by client.
     * @param string $displayName Original filename.
     * @param int $folderRetainId Retained folder id from listing (0 root).
     * @return array{upload_id: string, chunk_size_bytes: int, temp_max_bytes: int}
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function createSession(int $ownerUserId, int $expectedBytes, string $displayName, int $folderRetainId): array
    {
        $this->purgeExpiredForOwner($ownerUserId);
        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerUserId, $expectedBytes);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                throw new \RuntimeException('chunk_upload.quota_exceeded', 0, $e);
            }

            throw $e;
        }
        if ($folderRetainId > 0 && !$this->folderTreeService->resolveCurrentFolder($ownerUserId, $folderRetainId) instanceof Folder) {
            throw new \RuntimeException('chunk_upload.folder_invalid');
        }

        $uploadId = bin2hex(random_bytes(16));
        $baseDir = $this->sessionBaseDir($ownerUserId);
        if (!is_dir($baseDir) && !mkdir($baseDir, 0775, true) && !is_dir($baseDir)) {
            throw new \RuntimeException('chunk_upload.mkdir_failed');
        }

        $partPath = $this->partPath($ownerUserId, $uploadId);
        $metaPath = $this->metaPath($ownerUserId, $uploadId);

        if (file_put_contents($partPath, '') === false) {
            throw new \RuntimeException('chunk_upload.part_init_failed');
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
        if (file_put_contents($metaPath, json_encode($meta, JSON_THROW_ON_ERROR)) === false) {
            @unlink($partPath);
            throw new \RuntimeException('chunk_upload.meta_write_failed');
        }

        return [
            'upload_id' => $uploadId,
            'chunk_size_bytes' => max(1024, $this->chunkBytesDefault),
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
        if (!$chunk->isValid()) {
            throw new \RuntimeException('chunk_upload.chunk_invalid');
        }

        $meta = $this->loadMeta($ownerUserId, $uploadId);
        if ($meta === null) {
            throw new \RuntimeException('chunk_upload.session_not_found');
        }

        $expectedIndex = (int) ($meta['next_chunk_index'] ?? 0);
        if ($chunkIndex !== $expectedIndex) {
            throw new \RuntimeException('chunk_upload.chunk_order');
        }

        $partPath = $this->partPath($ownerUserId, $uploadId);
        $append = @file_get_contents($chunk->getRealPath());
        if (!is_string($append)) {
            throw new \RuntimeException('chunk_upload.chunk_read_failed');
        }

        $len = strlen($append);
        $received = (int) ($meta['received_bytes'] ?? 0);
        $expectedTotal = (int) ($meta['expected_bytes'] ?? 0);
        if ($received + $len > $expectedTotal) {
            throw new \RuntimeException('chunk_upload.size_overflow');
        }

        $out = fopen($partPath, 'ab');
        if ($out === false) {
            throw new \RuntimeException('chunk_upload.append_failed');
        }
        try {
            fwrite($out, $append);
        } finally {
            fclose($out);
        }

        $received += $len;
        $meta['received_bytes'] = $received;
        $meta['next_chunk_index'] = $expectedIndex + 1;
        $this->saveMeta($ownerUserId, $uploadId, $meta);

        return [
            'received_bytes' => $received,
            'complete' => $received >= $expectedTotal && $expectedTotal >= 0,
        ];
    }

    /**
     * @brief Encrypt assembled plaintext to storage and persist SharedFile.
     * @param int $effectiveOwnerId Owner namespace for session paths and SharedFile row (must match createSession/appendChunk).
     * @param string $uploadId Session id.
     * @param int $maxUploadBytes Effective max plaintext bytes.
     * @return SharedFile
     * @date 2026-05-08
     * @author Stephane H.
     */
    public function finalizeAndPersist(int $effectiveOwnerId, string $uploadId, int $maxUploadBytes): SharedFile
    {
        $ownerId = $effectiveOwnerId;
        $meta = $this->loadMeta($ownerId, $uploadId);
        if ($meta === null) {
            throw new \RuntimeException('chunk_upload.session_not_found');
        }

        $expectedTotal = (int) ($meta['expected_bytes'] ?? 0);
        $received = (int) ($meta['received_bytes'] ?? 0);
        if ($received !== $expectedTotal || $expectedTotal < 1) {
            throw new \RuntimeException('chunk_upload.incomplete');
        }

        if ($expectedTotal > $maxUploadBytes) {
            throw new \RuntimeException('chunk_upload.too_large');
        }

        try {
            $this->userStorageQuotaService->assertOwnerCanStoreBytes($ownerId, $expectedTotal);
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === UserStorageQuotaService::EXCEPTION_QUOTA_EXCEEDED) {
                throw new \RuntimeException('chunk_upload.quota_exceeded', 0, $e);
            }

            throw $e;
        }

        $displayName = trim((string) ($meta['display_name'] ?? ''));
        if ($displayName === '') {
            $displayName = 'file';
        }

        $targetFolderId = (int) ($meta['folder_retain_id'] ?? 0);
        $targetFolder = $this->folderTreeService->resolveCurrentFolder($ownerId, $targetFolderId > 0 ? $targetFolderId : null);
        if ($targetFolderId > 0 && !$targetFolder instanceof Folder) {
            throw new \RuntimeException('chunk_upload.folder_invalid');
        }
        $normalizedDisplay = Folder::normalizeName($displayName);

        if ($this->sharedFileRepository->findConflictingOwnedFileByNormalizedName($ownerId, $targetFolder, $normalizedDisplay, null) instanceof SharedFile) {
            throw new \RuntimeException('chunk_upload.name_conflict');
        }
        if ($this->folderRepository->findOneByOwnerParentAndNormalizedName($ownerId, $targetFolder, $normalizedDisplay) instanceof Folder) {
            throw new \RuntimeException('chunk_upload.name_conflict');
        }

        $partPath = $this->partPath($ownerId, $uploadId);
        if (!is_readable($partPath)) {
            throw new \RuntimeException('chunk_upload.part_missing');
        }

        $relativeDir = \sprintf('var/shared/%d', $ownerId);
        $absoluteDir = $this->projectDir.'/'.$relativeDir;
        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
            throw new \RuntimeException('chunk_upload.storage_failed');
        }

        $storageName = bin2hex(random_bytes(16)).'.dat';
        $absolutePath = $absoluteDir.'/'.$storageName;

        $plainSize = $this->fileEncryptionService->encryptPlainFileToV2Storage($partPath, $absolutePath);

        @unlink($partPath);
        @unlink($this->metaPath($ownerId, $uploadId));

        $sharedFile = new SharedFile(
            $ownerId,
            $absolutePath,
            'private',
            bin2hex(random_bytes(16)),
            $displayName,
            $plainSize,
            null,
            null
        );
        $sharedFile->setFolder($targetFolder);

        $this->entityManager->persist($sharedFile);
        $this->entityManager->flush();

        return $sharedFile;
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
        @unlink($this->partPath($ownerUserId, $uploadId));
        @unlink($this->metaPath($ownerUserId, $uploadId));
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
        file_put_contents($path, json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function sessionBaseDir(int $ownerUserId): string
    {
        return $this->projectDir.'/var/chunk_upload/'.$ownerUserId;
    }

    private function partPath(int $ownerUserId, string $uploadId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$uploadId.self::PART_SUFFIX;
    }

    private function metaPath(int $ownerUserId, string $uploadId): string
    {
        return $this->sessionBaseDir($ownerUserId).'/'.$uploadId.self::META_SUFFIX;
    }
}
