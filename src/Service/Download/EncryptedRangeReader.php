<?php

declare(strict_types=1);

namespace App\Service\Download;

use App\Service\File\FileEncryptionService;
use App\Service\File\V2SegmentIndexService;

/**
 * @brief Read plaintext byte ranges from encrypted v2 storage with segment-index seeks.
 * @date 2026-11-02
 * @author Stephane H.
 */
final class EncryptedRangeReader
{
    /**
     * @brief Construct range reader.
     * @param FileEncryptionService $fileEncryptionService Encryption helpers.
     * @param V2SegmentIndexService $segmentIndexService Segment index sidecars.
     * @param int $lazyIndexMaxBytes Max size for on-demand index build.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function __construct(
        private readonly FileEncryptionService $fileEncryptionService,
        private readonly V2SegmentIndexService $segmentIndexService,
        private readonly int $lazyIndexMaxBytes = 1073741824,
    ) {
    }

    /**
     * @brief Whether storage is v2 and Range-capable.
     * @param string $storagePath Encrypted path.
     * @return bool
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function supportsRanges(string $storagePath): bool
    {
        return $this->fileEncryptionService->isV2StorageFormat($storagePath);
    }

    /**
     * @brief Ensure a segment index exists for v2 storage (lazy build under size threshold).
     * @param string $storagePath Encrypted path.
     * @param int $plainTotal Declared plaintext size.
     * @return void
     * @throws \RuntimeException When index is missing and too large to build lazily.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function ensureIndex(string $storagePath, int $plainTotal): void
    {
        if (!$this->fileEncryptionService->isV2StorageFormat($storagePath)) {
            return;
        }

        if ($this->segmentIndexService->loadIndex($storagePath) !== null) {
            return;
        }

        if ($plainTotal > $this->lazyIndexMaxBytes) {
            throw new \RuntimeException('download.index_missing');
        }

        $this->segmentIndexService->buildIndexIfMissing($storagePath, false);
    }

    /**
     * @brief Emit a plaintext range as chunks into a sink callback (bounded memory).
     * @param string $storagePath Encrypted path.
     * @param int $plainStart Inclusive plaintext offset.
     * @param int $plainLength Number of bytes.
     * @param callable(string): void $sink Chunk consumer.
     * @return int Bytes written.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function readRange(string $storagePath, int $plainStart, int $plainLength, callable $sink): int
    {
        if ($plainStart < 0 || $plainLength < 1) {
            throw new \RuntimeException('file.encryption.range_invalid');
        }

        if (!$this->fileEncryptionService->isV2StorageFormat($storagePath)) {
            throw new \RuntimeException('file.encryption.range_v2_only');
        }

        $handle = CallbackWriteStream::open($sink);
        try {
            $index = $this->segmentIndexService->loadIndex($storagePath);
            if ($index !== null) {
                return $this->fileEncryptionService->streamDecryptStorageRangeFromIndex(
                    $storagePath,
                    $index,
                    $plainStart,
                    $plainLength,
                    $handle,
                );
            }

            return $this->fileEncryptionService->streamDecryptStorageRangeToHandle(
                $storagePath,
                $plainStart,
                $plainLength,
                $handle,
            );
        } finally {
            fclose($handle);
        }
    }

    /**
     * @brief Stream entire plaintext into sink (legacy v1 or full v2).
     * @param string $storagePath Encrypted path.
     * @param callable(string): void $sink Chunk consumer.
     * @return int Bytes written.
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function readAll(string $storagePath, callable $sink): int
    {
        $handle = CallbackWriteStream::open($sink);
        try {
            return $this->fileEncryptionService->streamDecryptStorageToHandle($storagePath, $handle);
        } finally {
            fclose($handle);
        }
    }
}
