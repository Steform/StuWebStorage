<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * @brief Load, persist, and build v2 segment index sidecars (.cvf2idx).
 * @author Stephane H.
 * @date 2026-07-07
 */
final class V2SegmentIndexService
{
    public const INDEX_VERSION = 1;

    public const INDEX_EXTENSION = '.cvf2idx';

    public function __construct(
        private readonly FileEncryptionService $fileEncryptionService,
    ) {
    }

    /**
     * @brief Resolve sidecar path for a storage file.
     * @param string $storagePath Encrypted storage path.
     * @return string
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function indexPath(string $storagePath): string
    {
        return $storagePath.self::INDEX_EXTENSION;
    }

    /**
     * @brief Load index sidecar when present and valid.
     * @param string $storagePath Encrypted storage path.
     * @return V2SegmentIndex|null
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function loadIndex(string $storagePath): ?V2SegmentIndex
    {
        $path = $this->indexPath($storagePath);
        if (!is_readable($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $payload */
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        $version = (int) ($payload['version'] ?? 0);
        $plainChunkBytes = (int) ($payload['plain_chunk_bytes'] ?? 0);
        $plainTotal = (int) ($payload['plain_total'] ?? 0);
        $cipherOffsets = $payload['cipher_offsets'] ?? null;
        if ($version !== self::INDEX_VERSION || $plainChunkBytes < 1 || $plainTotal < 1 || !is_array($cipherOffsets)) {
            return null;
        }

        $offsets = [];
        foreach ($cipherOffsets as $offset) {
            if (!is_int($offset) && !is_numeric($offset)) {
                return null;
            }
            $offsets[] = (int) $offset;
        }

        if ($offsets === []) {
            return null;
        }

        return new V2SegmentIndex($version, $plainChunkBytes, $plainTotal, $offsets);
    }

    /**
     * @brief Persist index sidecar atomically.
     * @param string $storagePath Encrypted storage path.
     * @param int $plainTotal Total plaintext bytes.
     * @param array<int, int> $cipherOffsets Cipher offset per segment.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function saveIndex(string $storagePath, int $plainTotal, array $cipherOffsets): void
    {
        $payload = [
            'version' => self::INDEX_VERSION,
            'plain_chunk_bytes' => FileEncryptionService::PLAIN_CHUNK_BYTES,
            'plain_total' => $plainTotal,
            'cipher_offsets' => array_values($cipherOffsets),
        ];

        $target = $this->indexPath($storagePath);
        $tmp = $target.'.tmp';
        file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR));
        if (!rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException('file.encryption.index_write_failed');
        }
    }

    /**
     * @brief Scan v2 storage and build missing index sidecar.
     * @param string $storagePath Encrypted storage path.
     * @param bool $dryRun When true, do not write files.
     * @return bool True when an index was built or already exists.
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function buildIndexIfMissing(string $storagePath, bool $dryRun = false): bool
    {
        if (!$this->fileEncryptionService->isV2StorageFormat($storagePath)) {
            return false;
        }

        if ($this->loadIndex($storagePath) !== null) {
            return true;
        }

        $built = $this->fileEncryptionService->scanV2CipherOffsets($storagePath);
        if ($dryRun) {
            return $built['cipher_offsets'] !== [];
        }

        $this->saveIndex($storagePath, $built['plain_total'], $built['cipher_offsets']);

        return true;
    }
}
