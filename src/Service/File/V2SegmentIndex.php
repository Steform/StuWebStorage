<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * @brief Parsed v2 segment index sidecar for O(1) cipher seeks.
 * @author Stephane H.
 * @date 2026-07-07
 */
final class V2SegmentIndex
{
    /**
     * @brief Construct segment index aggregate.
     * @param int $version Index format version.
     * @param int $plainChunkBytes Plaintext bytes per segment.
     * @param int $plainTotal Total plaintext size.
     * @param array<int, int> $cipherOffsets Cipher file offset per segment index.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function __construct(
        public readonly int $version,
        public readonly int $plainChunkBytes,
        public readonly int $plainTotal,
        public readonly array $cipherOffsets,
    ) {
    }

    /**
     * @brief Resolve cipher file offset for a plaintext start byte.
     * @param int $plainStart Inclusive plaintext offset.
     * @return int Cipher file offset.
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function resolveCipherOffset(int $plainStart): int
    {
        if ($this->plainChunkBytes < 1) {
            throw new \RuntimeException('file.encryption.index_invalid');
        }

        $segmentIndex = (int) floor($plainStart / $this->plainChunkBytes);
        if (!isset($this->cipherOffsets[$segmentIndex])) {
            throw new \RuntimeException('file.encryption.index_invalid');
        }

        return (int) $this->cipherOffsets[$segmentIndex];
    }
}
