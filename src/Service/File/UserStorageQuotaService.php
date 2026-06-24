<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\User;
use App\Repository\SharedFileRepository;
use App\Repository\UserRepository;

/**
 * @brief Resolve per-user storage quotas and enforce remaining capacity.
 */
final class UserStorageQuotaService
{
    public const QUOTA_SOURCE_USER = 'user';

    public const QUOTA_SOURCE_DEFAULT = 'default';

    public const QUOTA_SOURCE_UNLIMITED = 'unlimited';

    public const EXCEPTION_QUOTA_EXCEEDED = 'storage_quota.exceeded';

    /**
     * @param SharedFileRepository $sharedFileRepository Shared file repository.
     * @param UserRepository $userRepository User repository.
     * @param int $defaultQuotaBytes Platform default quota in bytes (0 means unlimited).
     */
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly UserRepository $userRepository,
        private readonly int $defaultQuotaBytes = 0,
    ) {
    }

    /**
     * @brief Resolve effective quota bytes for one user (0 means unlimited).
     *
     * @param User $user Target user.
     * @return int Effective quota bytes.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function resolveEffectiveQuotaBytes(User $user): int
    {
        $userQuota = $user->getStorageQuotaBytes();
        if ($userQuota !== null) {
            return max(0, $userQuota);
        }

        return max(0, $this->defaultQuotaBytes);
    }

    /**
     * @brief Resolve whether quota comes from user override, platform default, or unlimited.
     *
     * @param User $user Target user.
     * @return string One of QUOTA_SOURCE_* constants.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function resolveQuotaSource(User $user): string
    {
        $userQuota = $user->getStorageQuotaBytes();
        if ($userQuota !== null) {
            return $userQuota < 1 ? self::QUOTA_SOURCE_UNLIMITED : self::QUOTA_SOURCE_USER;
        }

        return $this->defaultQuotaBytes < 1 ? self::QUOTA_SOURCE_UNLIMITED : self::QUOTA_SOURCE_DEFAULT;
    }

    /**
     * @brief Sum stored bytes for one owner user.
     *
     * @param int $ownerUserId Owner user identifier.
     * @return int Used bytes.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function sumUsedBytesByOwner(int $ownerUserId): int
    {
        if ($ownerUserId < 1) {
            return 0;
        }

        return (int) $this->sharedFileRepository->createQueryBuilder('sf')
            ->select('COALESCE(SUM(sf.byteSize), 0)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Compute remaining bytes for one user (null when unlimited).
     *
     * @param User $user Target user.
     * @param int|null $usedBytes Optional precomputed used bytes.
     * @return int|null Remaining bytes or null when unlimited.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function resolveRemainingBytes(User $user, ?int $usedBytes = null): ?int
    {
        $effectiveQuota = $this->resolveEffectiveQuotaBytes($user);
        if ($effectiveQuota < 1) {
            return null;
        }

        $used = $usedBytes ?? $this->sumUsedBytesByOwner((int) $user->getId());

        return max(0, $effectiveQuota - $used);
    }

    /**
     * @brief Ensure an owner can store additional bytes without exceeding quota.
     *
     * @param int $ownerUserId Owner user identifier.
     * @param int $additionalBytes Bytes to add.
     * @return void
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function assertOwnerCanStoreBytes(int $ownerUserId, int $additionalBytes): void
    {
        if ($ownerUserId < 1 || $additionalBytes < 1) {
            return;
        }

        $user = $this->userRepository->find($ownerUserId);
        if (!$user instanceof User) {
            throw new \RuntimeException(self::EXCEPTION_QUOTA_EXCEEDED);
        }

        $effectiveQuota = $this->resolveEffectiveQuotaBytes($user);
        if ($effectiveQuota < 1) {
            return;
        }

        $usedBytes = $this->sumUsedBytesByOwner($ownerUserId);
        if ($usedBytes + $additionalBytes > $effectiveQuota) {
            throw new \RuntimeException(self::EXCEPTION_QUOTA_EXCEEDED);
        }
    }

    /**
     * @brief Parse admin GiB input into nullable quota bytes.
     *
     * @param string $raw Raw form value.
     * @return array{quotaBytes: int|null, errorKey: string|null}
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function parseAdminGiBInput(string $raw): array
    {
        $normalized = trim(str_replace(',', '.', $raw));
        if ($normalized === '') {
            return ['quotaBytes' => null, 'errorKey' => null];
        }

        if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
            return ['quotaBytes' => null, 'errorKey' => 'admin.users.error.invalid_storage_quota'];
        }

        $gib = (float) $normalized;
        if ($gib < 0) {
            return ['quotaBytes' => null, 'errorKey' => 'admin.users.error.invalid_storage_quota'];
        }

        if ($gib === 0.0) {
            return ['quotaBytes' => 0, 'errorKey' => null];
        }

        $bytes = (int) round($gib * 1024 * 1024 * 1024);

        return ['quotaBytes' => max(1, $bytes), 'errorKey' => null];
    }

    /**
     * @brief Format nullable quota bytes as GiB for admin forms.
     *
     * @param int|null $quotaBytes Quota bytes or null.
     * @return string Empty string when null, otherwise GiB with up to two decimals.
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function formatQuotaGiBForAdmin(?int $quotaBytes): string
    {
        if ($quotaBytes === null) {
            return '';
        }

        if ($quotaBytes < 1) {
            return '0';
        }

        $gib = $quotaBytes / (1024 * 1024 * 1024);

        return rtrim(rtrim(number_format($gib, 2, '.', ''), '0'), '.');
    }
}
