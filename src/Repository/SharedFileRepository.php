<?php

namespace App\Repository;

use App\Entity\Folder;
use App\Entity\ShareGrant;
use App\Entity\SharedFile;
use App\Entity\User;
use App\File\SharedFileOwnerListCriteria;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository SharedFileRepository.
 *
 * @extends ServiceEntityRepository<SharedFile>
 */
class SharedFileRepository extends ServiceEntityRepository
{
    /**
     * @brief Build shared file repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SharedFile::class);
    }

    /**
     * @brief Find a shared file by its public token.
     * @param string $publicToken Public file token.
     * @return SharedFile|null
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function findOneByPublicToken(string $publicToken): ?SharedFile
    {
        return $this->findOneBy(['publicToken' => $publicToken]);
    }

    /**
     * @brief Find another owned file in the same folder level whose display name normalizes to the given token.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder|null $folder Parent folder or null for owner root.
     * @param string $normalizedName Normalized name (same rules as Folder::normalizeName).
     * @param int|null $excludeSharedFileId Optional file id to ignore (rename-in-place).
     * @return SharedFile|null
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function findConflictingOwnedFileByNormalizedName(
        int $ownerUserId,
        ?Folder $folder,
        string $normalizedName,
        ?int $excludeSharedFileId
    ): ?SharedFile {
        $qb = $this->createQueryBuilder('sf')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId);
        if ($folder instanceof Folder) {
            $qb->andWhere('sf.folder = :folder')->setParameter('folder', $folder);
        } else {
            $qb->andWhere('sf.folder IS NULL');
        }
        /** @var array<int, SharedFile> $rows */
        $rows = $qb->getQuery()->getResult();
        foreach ($rows as $sf) {
            if (!$sf instanceof SharedFile) {
                continue;
            }
            $sid = $sf->getId();
            if ($excludeSharedFileId !== null && (int) $sid === $excludeSharedFileId) {
                continue;
            }
            if (Folder::normalizeName($sf->getOriginalFileName()) === $normalizedName) {
                return $sf;
            }
        }

        return null;
    }

    /**
     * @brief Paginate shared files for an owner ordered by upload time descending.
     * @param int $ownerUserId Owner user identifier.
     * @param int $page One-based page index.
     * @param int $perPage Page size.
     * @return array<int, SharedFile>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findByOwnerPage(int $ownerUserId, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        return $this->createQueryBuilder('sf')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->orderBy('sf.uploadedAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Count shared files owned by a user.
     * @param int $ownerUserId Owner user identifier.
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function countByOwner(int $ownerUserId): int
    {
        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Find shared files expired strictly before the given instant.
     * @param \DateTimeImmutable $now Reference instant.
     * @return array<int, SharedFile>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findExpiredBefore(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('sf')
            ->andWhere('sf.expiresAt IS NOT NULL')
            ->andWhere('sf.expiresAt < :now')
            ->setParameter('now', $now)
            ->orderBy('sf.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Paginate owned files with filters and sorting (local SQL only).
     * @param int $ownerUserId Owner user identifier.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @param int $page One-based page index.
     * @param int $perPage Page size.
     * @return array<int, SharedFile>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findOwnedPageFiltered(
        int $ownerUserId,
        SharedFileOwnerListCriteria $criteria,
        int $page,
        int $perPage
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $qb = $this->createQueryBuilder('sf');
        $qb->andWhere('sf.ownerUserId = :owner')->setParameter('owner', $ownerUserId);
        $this->applyOwnerListCriteria($qb, $criteria);
        $this->applyOwnerListOrderBy($qb, $criteria);

        return $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief List all owned files matching filters and sorting without pagination (Sprint 19).
     * @param int $ownerUserId Owner user identifier.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @return array<int, SharedFile>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findOwnedFilteredAll(int $ownerUserId, SharedFileOwnerListCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('sf');
        $qb->andWhere('sf.ownerUserId = :owner')->setParameter('owner', $ownerUserId);
        $this->applyOwnerListCriteria($qb, $criteria);
        $this->applyOwnerListOrderBy($qb, $criteria);

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief List owned files in one folder level with filters and sorting.
     * @param int $ownerUserId Owner user identifier.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @param Folder|null $folder Current folder or null for root.
     * @return array<int, SharedFile>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function findOwnedFilteredInFolder(int $ownerUserId, SharedFileOwnerListCriteria $criteria, ?Folder $folder): array
    {
        $qb = $this->createQueryBuilder('sf');
        $qb->andWhere('sf.ownerUserId = :owner')->setParameter('owner', $ownerUserId);
        if ($folder instanceof Folder) {
            $qb->andWhere('sf.folder = :folder')->setParameter('folder', $folder);
        } else {
            $qb->andWhere('sf.folder IS NULL');
        }
        $this->applyOwnerListCriteria($qb, $criteria);
        $this->applyOwnerListOrderBy($qb, $criteria);

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief List files for admin godview with owner optional filtering and listing sort criteria.
     * @param int|null $ownerUserId Optional owner filter (null means all owners).
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @return array<int, SharedFile>
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function findAdminFilteredAll(?int $ownerUserId, SharedFileOwnerListCriteria $criteria): array
    {
        $qb = $this->createQueryBuilder('sf');
        if ($ownerUserId !== null && $ownerUserId > 0) {
            $qb->andWhere('sf.ownerUserId = :owner')->setParameter('owner', $ownerUserId);
        }
        $this->applyOwnerListCriteria($qb, $criteria);
        $this->applyOwnerListOrderBy($qb, $criteria);

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief List files shared with a grantee user through active friend grants with optional listing sort criteria.
     * @param int $granteeUserId Grantee user identifier.
     * @param SharedFileOwnerListCriteria|null $criteria Optional sorting criteria.
     * @return array<int, SharedFile>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findSharedForGranteeAll(int $granteeUserId, ?SharedFileOwnerListCriteria $criteria = null): array
    {
        $qb = $this->createQueryBuilder('sf')
            ->distinct()
            ->innerJoin(ShareGrant::class, 'sg', 'WITH', 'sg.sharedFileId = sf.id')
            ->andWhere('sg.granteeUserId = :granteeUserId')
            ->andWhere('sg.expiresAt IS NULL OR sg.expiresAt > CURRENT_TIMESTAMP()')
            ->andWhere('sf.expiresAt IS NULL OR sf.expiresAt > CURRENT_TIMESTAMP()')
            ->andWhere('sf.ownerUserId != :granteeUserId')
            ->setParameter('granteeUserId', $granteeUserId);
        if ($criteria instanceof SharedFileOwnerListCriteria) {
            $this->applyOwnerListOrderBy($qb, $criteria);
        } else {
            $qb->orderBy('sf.updatedAt', 'DESC')
                ->addOrderBy('sf.uploadedAt', 'DESC');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief Count owned rows matching listing filters.
     * @param int $ownerUserId Owner user identifier.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function countOwnedFiltered(int $ownerUserId, SharedFileOwnerListCriteria $criteria): int
    {
        $qb = $this->createQueryBuilder('sf')->select('COUNT(sf.id)');
        $qb->andWhere('sf.ownerUserId = :owner')->setParameter('owner', $ownerUserId);
        $this->applyOwnerListCriteria($qb, $criteria);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @brief List distinct non-empty extension tokens for an owner.
     * @param int $ownerUserId Owner user identifier.
     * @return array<int, string>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findDistinctExtensionsByOwner(int $ownerUserId): array
    {
        $rows = $this->createQueryBuilder('sf')
            ->select('sf.fileExtension AS ext')
            ->distinct()
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.fileExtension <> :empty')
            ->setParameter('empty', '')
            ->orderBy('sf.fileExtension', 'ASC')
            ->getQuery()
            ->getScalarResult();

        $out = [];
        foreach ($rows as $row) {
            if (isset($row['ext']) && $row['ext'] !== '') {
                $out[] = (string) $row['ext'];
            }
        }

        return $out;
    }

    /**
     * @brief Count owner files attached to the provided folder identifiers.
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function countByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Sum owner byte size for files attached to the provided folder identifiers.
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function sumByteSizeByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        $sum = $this->createQueryBuilder('sf')
            ->select('COALESCE(SUM(sf.byteSize), 0)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $sum;
    }

    /**
     * @brief Count owner files whose public channel is currently active (matches SharedFile::isPublicShareActive()).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function countActivePublicByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        $now = new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->andWhere(
                '(sf.isPublic = true AND (sf.publicExpiresAt IS NULL OR sf.publicExpiresAt > :now)) OR '.
                '(sf.isPublic = false AND sf.visibility = :publicVisibility AND (sf.expiresAt IS NULL OR sf.expiresAt > :now))'
            )
            ->setParameter('now', $now)
            ->setParameter('publicVisibility', 'public')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Count owner files with an active public link and a finite expiry (for listing clock icon), aligned with SharedFile::isPublicShareActive() and non-null effective expiry.
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function countActivePublicWithFiniteExpiryByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        $now = new \DateTimeImmutable();

        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->andWhere(
                '(sf.isPublic = true AND sf.publicExpiresAt IS NOT NULL AND sf.publicExpiresAt > :now) OR '.
                '(sf.isPublic = false AND sf.visibility = :publicVisibility AND sf.expiresAt IS NOT NULL AND sf.expiresAt > :now)'
            )
            ->setParameter('now', $now)
            ->setParameter('publicVisibility', 'public')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Count owner files in folders where public sharing has a configured expiration instant (is_public with non-null public_expires_at).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function countWithPublicExpirationConfiguredByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->andWhere('sf.isPublic = :true')
            ->setParameter('true', true)
            ->andWhere('sf.publicExpiresAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Count owner files with at least one active friends grant inside the provided folder identifiers.
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers.
     * @return int
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function countActiveFriendsByOwnerAndFolderIds(int $ownerUserId, array $folderIds): int
    {
        if ($folderIds === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('sf')
            ->select('COUNT(sf.id)')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->andWhere(
                'EXISTS (
                    SELECT 1 FROM '.ShareGrant::class.' sgActive
                    WHERE sgActive.sharedFileId = sf.id
                    AND (sgActive.expiresAt IS NULL OR sgActive.expiresAt > CURRENT_TIMESTAMP())
                )'
            )
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Return one public token for any file under the folders whose public channel is active (matches SharedFile::isPublicShareActive(), same as public landing).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers (typically one subtree).
     * @return string|null Non-empty public token or null when none qualify.
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findOneActivePublicTokenForOwnerAndFolderIds(int $ownerUserId, array $folderIds): ?string
    {
        if ($folderIds === []) {
            return null;
        }

        $candidates = $this->createQueryBuilder('sf')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('folderIds', $folderIds)
            ->orderBy('sf.id', 'ASC')
            ->setMaxResults(500)
            ->getQuery()
            ->getResult();

        foreach ($candidates as $file) {
            if (!$file instanceof SharedFile) {
                continue;
            }
            if ($file->getPublicToken() === '') {
                continue;
            }
            if ($file->isPublicShareActive()) {
                return $file->getPublicToken();
            }
        }

        return null;
    }

    /**
     * @brief Apply listing predicates to a query builder, including glob (*, ?) on file name when search contains wildcards.
     * @param QueryBuilder $qb Query builder with alias sf.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function applyOwnerListCriteria(QueryBuilder $qb, SharedFileOwnerListCriteria $criteria): void
    {
        $q = trim($criteria->searchQuery);
        if ($q !== '') {
            $hasGlob = strpbrk($q, '*?') !== false;
            if ($hasGlob) {
                $lower = mb_strtolower($q);
                $escaped = strtr($lower, ['!' => '!!', '%' => '!%', '_' => '!_']);
                $like = strtr($escaped, ['*' => '%', '?' => '_']);
                $qb->andWhere("LOWER(sf.originalFileName) LIKE :filesQ ESCAPE '!'")
                    ->setParameter('filesQ', $like);
            } else {
                $like = '%'.addcslashes(mb_strtolower($q), '%_\\').'%';
                $qb->andWhere(
                    '(LOWER(sf.originalFileName) LIKE :filesQ OR LOWER(sf.fileExtension) LIKE :filesQ)'
                )->setParameter('filesQ', $like);
            }
        }

        if ($criteria->filterPublic === 'yes') {
            $qb->andWhere('sf.visibility = :pubVis')->setParameter('pubVis', 'public');
        } elseif ($criteria->filterPublic === 'no') {
            $qb->andWhere('sf.visibility = :privVis')->setParameter('privVis', 'private');
        }

        if ($criteria->extensionFilters !== []) {
            $qb->andWhere('sf.fileExtension IN (:extFil)')->setParameter('extFil', $criteria->extensionFilters);
        }

        if ($criteria->uploadedAfter instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.uploadedAt >= :uploadedAfter')->setParameter('uploadedAfter', $criteria->uploadedAfter);
        }
        if ($criteria->uploadedBefore instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.uploadedAt <= :uploadedBefore')->setParameter('uploadedBefore', $criteria->uploadedBefore);
        }
        if ($criteria->updatedAfter instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.updatedAt >= :updatedAfter')->setParameter('updatedAfter', $criteria->updatedAfter);
        }
        if ($criteria->updatedBefore instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.updatedAt <= :updatedBefore')->setParameter('updatedBefore', $criteria->updatedBefore);
        }
        if ($criteria->expiresAfter instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.expiresAt IS NOT NULL AND sf.expiresAt >= :expiresAfter')->setParameter('expiresAfter', $criteria->expiresAfter);
        }
        if ($criteria->expiresBefore instanceof \DateTimeImmutable) {
            $qb->andWhere('sf.expiresAt IS NOT NULL AND sf.expiresAt <= :expiresBefore')->setParameter('expiresBefore', $criteria->expiresBefore);
        }

        if ($criteria->filterHasGrant === 'yes') {
            $qb->andWhere('EXISTS (SELECT 1 FROM '.ShareGrant::class.' sgHas WHERE sgHas.sharedFileId = sf.id)');
        } elseif ($criteria->filterHasGrant === 'no') {
            $qb->andWhere('NOT EXISTS (SELECT 1 FROM '.ShareGrant::class.' sgNo WHERE sgNo.sharedFileId = sf.id)');
        }

        if ($criteria->granteeUserIds !== []) {
            $qb->andWhere(
                'EXISTS (SELECT 1 FROM '.ShareGrant::class.' sgPick WHERE sgPick.sharedFileId = sf.id AND sgPick.granteeUserId IN (:granteePick))'
            )->setParameter('granteePick', $criteria->granteeUserIds);
        }
    }

    /**
     * @brief Apply listing ORDER BY with a whitelisted sort map or neutral fallback order.
     * @param QueryBuilder $qb Query builder with alias sf.
     * @param SharedFileOwnerListCriteria $criteria Listing filters.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    private function applyOwnerListOrderBy(QueryBuilder $qb, SharedFileOwnerListCriteria $criteria): void
    {
        if ($criteria->isSortNeutral()) {
            $qb->orderBy('sf.id', 'DESC');

            return;
        }

        $dir = strtoupper($criteria->sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        switch ($criteria->sortField) {
            case 'name':
                $qb->orderBy('sf.originalFileName', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'size':
                $qb->orderBy('sf.byteSize', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'modified':
                $qb->orderBy('sf.updatedAt', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'ext':
                $qb->orderBy('sf.fileExtension', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'pseudo':
                $qb
                    ->leftJoin(User::class, 'sortOwnerUser', 'WITH', 'sortOwnerUser.id = sf.ownerUserId')
                    ->addSelect(
                        "(CASE
                            WHEN sortOwnerUser.pseudonym IS NULL OR sortOwnerUser.pseudonym = ''
                            THEN sortOwnerUser.email
                            ELSE sortOwnerUser.pseudonym
                        END) AS HIDDEN sortOwnerLabel"
                    )
                    ->orderBy('sortOwnerLabel', $dir);
                $qb->addOrderBy('sf.ownerUserId', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'share_public':
                $qb->orderBy('sf.isPublicShareActive', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'share_friends':
                $qb
                    ->addSelect(
                        '(CASE WHEN EXISTS (
                            SELECT 1 FROM '.ShareGrant::class.' sgOrder
                            WHERE sgOrder.sharedFileId = sf.id
                            AND (sgOrder.expiresAt IS NULL OR sgOrder.expiresAt > CURRENT_TIMESTAMP())
                        ) THEN 1 ELSE 0 END) AS HIDDEN sortHasFriendsShare'
                    )
                    ->orderBy('sortHasFriendsShare', $dir);
                $qb->addOrderBy('sf.id', 'DESC');

                return;
            case 'uploaded':
            default:
                $qb->orderBy('sf.uploadedAt', $dir);
                $qb->addOrderBy('sf.id', 'DESC');
        }
    }
}
