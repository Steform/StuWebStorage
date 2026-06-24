<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Dto\File\ZipExtractLimits;

/**
 * @brief Resolve ZIP extraction limits for standard users vs elevated admin actors.
 * @author Stephane H.
 * @date 2026-06-24
 */
final class ZipExtractLimitsResolver
{
    public const TIER_STANDARD = 'standard';

    public const TIER_ADMIN = 'admin';

    public function __construct(
        private readonly int $maxTotalBytes,
        private readonly int $adminMaxTotalBytes,
        private readonly int $maxFileCount,
        private readonly int $adminMaxFileCount,
        private readonly int $maxSeconds,
        private readonly int $adminMaxSeconds,
        private readonly int $batchSize,
        private readonly int $maxCompressionRatio,
    ) {
    }

    /**
     * @brief Resolve limits for the authenticated actor tier.
     * @param bool $isAdminActor Whether the actor has ROLE_ADMIN.
     * @return ZipExtractLimits
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function resolveForActor(bool $isAdminActor): ZipExtractLimits
    {
        if ($isAdminActor) {
            return new ZipExtractLimits(
                maxTotalBytes: max(0, $this->adminMaxTotalBytes),
                maxFileCount: max(1, $this->adminMaxFileCount),
                maxSeconds: max(1, $this->adminMaxSeconds),
                batchSize: max(1, $this->batchSize),
                maxCompressionRatio: max(1, $this->maxCompressionRatio),
                tier: self::TIER_ADMIN,
            );
        }

        return new ZipExtractLimits(
            maxTotalBytes: max(0, $this->maxTotalBytes),
            maxFileCount: max(1, $this->maxFileCount),
            maxSeconds: max(1, $this->maxSeconds),
            batchSize: max(1, $this->batchSize),
            maxCompressionRatio: max(1, $this->maxCompressionRatio),
            tier: self::TIER_STANDARD,
        );
    }
}
