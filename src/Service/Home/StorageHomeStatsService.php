<?php

declare(strict_types=1);

namespace App\Service\Home;

use App\Entity\SharedFile;
use App\Entity\User;
use App\Repository\FolderRepository;
use App\Repository\ShareGrantRepository;
use App\Repository\SharedFileRepository;
use App\Service\File\UserStorageQuotaService;

/**
 * @brief Aggregate real storage statistics for the cloud home dashboard.
 */
final class StorageHomeStatsService
{
    /**
     * @param SharedFileRepository $sharedFileRepository Shared file repository.
     * @param FolderRepository $folderRepository Folder repository.
     * @param ShareGrantRepository $shareGrantRepository Share grant repository.
     * @param UserStorageQuotaService $userStorageQuotaService Per-user quota resolver.
     */
    public function __construct(
        private readonly SharedFileRepository $sharedFileRepository,
        private readonly FolderRepository $folderRepository,
        private readonly ShareGrantRepository $shareGrantRepository,
        private readonly UserStorageQuotaService $userStorageQuotaService,
    ) {
    }

    /**
     * @brief Build storage stats for one owner user.
     *
     * @param User $ownerUser Owner user aggregate.
     * @return array{
     *     fileCount: int,
     *     totalBytes: int,
     *     remainingBytes: int|null,
     *     quotaBytes: int,
     *     quotaSource: string,
     *     folderCount: int,
     *     activeShares: int
     * }
     * @date 2026-06-22
     * @author Stephane H.
     */
    public function buildForOwner(User $ownerUser): array
    {
        $ownerUserId = (int) $ownerUser->getId();
        if ($ownerUserId < 1) {
            return [
                'fileCount' => 0,
                'totalBytes' => 0,
                'remainingBytes' => null,
                'quotaBytes' => 0,
                'quotaSource' => UserStorageQuotaService::QUOTA_SOURCE_UNLIMITED,
                'folderCount' => 0,
                'activeShares' => 0,
            ];
        }

        $fileCount = $this->sharedFileRepository->countByOwner($ownerUserId);
        $totalBytes = $this->userStorageQuotaService->sumUsedBytesByOwner($ownerUserId);
        $folderCount = $this->countFoldersByOwner($ownerUserId);
        $activeShares = $this->countActiveSharesByOwner($ownerUserId);
        $quotaBytes = $this->userStorageQuotaService->resolveEffectiveQuotaBytes($ownerUser);

        return [
            'fileCount' => $fileCount,
            'totalBytes' => $totalBytes,
            'remainingBytes' => $this->userStorageQuotaService->resolveRemainingBytes($ownerUser, $totalBytes),
            'quotaBytes' => $quotaBytes,
            'quotaSource' => $this->userStorageQuotaService->resolveQuotaSource($ownerUser),
            'folderCount' => $folderCount,
            'activeShares' => $activeShares,
        ];
    }

    /**
     * @brief Count folders owned by a user.
     *
     * @param int $ownerUserId Owner user identifier.
     * @return int Folder count.
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function countFoldersByOwner(int $ownerUserId): int
    {
        return (int) $this->folderRepository->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Count active public shares and friend grants for owned files.
     *
     * @param int $ownerUserId Owner user identifier.
     * @return int Active share count.
     * @date 2026-06-22
     * @author Stephane H.
     */
    private function countActiveSharesByOwner(int $ownerUserId): int
    {
        $now = new \DateTimeImmutable();

        $publicCount = (int) $this->sharedFileRepository->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.isPublic = true')
            ->andWhere('sf.publicExpiresAt IS NULL OR sf.publicExpiresAt > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        $grantCount = (int) $this->shareGrantRepository->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->innerJoin(SharedFile::class, 'sf', 'WITH', 'sf.id = g.sharedFileId')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('g.expiresAt IS NULL OR g.expiresAt > :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();

        return $publicCount + $grantCount;
    }
}
