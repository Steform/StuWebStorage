<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * @brief Effective ZIP extraction limits for one job or actor tier.
 * @author Stephane H.
 * @date 2026-06-24
 */
final readonly class ZipExtractLimits
{
    public function __construct(
        public int $maxTotalBytes,
        public int $maxFileCount,
        public int $maxSeconds,
        public int $batchSize,
        public int $maxCompressionRatio,
        public string $tier,
    ) {
    }

    /**
     * @brief Serialize limits for job session meta JSON.
     * @param void No input parameter.
     * @return array<string, int|string>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function toMetaArray(): array
    {
        return [
            'max_total_bytes' => $this->maxTotalBytes,
            'max_file_count' => $this->maxFileCount,
            'max_seconds' => $this->maxSeconds,
            'batch_size' => $this->batchSize,
            'max_compression_ratio' => $this->maxCompressionRatio,
            'tier' => $this->tier,
        ];
    }

    /**
     * @brief Restore limits from persisted job meta.
     * @param array<string, mixed> $meta Job meta payload.
     * @return self
     * @date 2026-06-24
     * @author Stephane H.
     */
    public static function fromJobMeta(array $meta): self
    {
        /** @var array<string, mixed> $limits */
        $limits = \is_array($meta['limits'] ?? null) ? $meta['limits'] : [];

        return new self(
            maxTotalBytes: max(0, (int) ($limits['max_total_bytes'] ?? 0)),
            maxFileCount: max(1, (int) ($limits['max_file_count'] ?? 1)),
            maxSeconds: max(1, (int) ($limits['max_seconds'] ?? 1)),
            batchSize: max(1, (int) ($limits['batch_size'] ?? 1)),
            maxCompressionRatio: max(1, (int) ($limits['max_compression_ratio'] ?? 100)),
            tier: (string) ($limits['tier'] ?? 'standard'),
        );
    }
}
