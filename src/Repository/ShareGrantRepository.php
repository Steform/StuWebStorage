<?php

namespace App\Repository;

use App\Entity\SharedFile;
use App\Entity\ShareGrant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository ShareGrantRepository.
 *
 * @extends ServiceEntityRepository<ShareGrant>
 */
class ShareGrantRepository extends ServiceEntityRepository
{
    /**
     * @brief Build share grant repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ShareGrant::class);
    }

    /**
     * @brief Check whether a grant exists for a user and file (any state, including expired).
     * @param int $sharedFileId Shared file identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @return bool
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function hasGrantForUser(int $sharedFileId, int $granteeUserId): bool
    {
        $count = (int) $this->createQueryBuilder('grant')
            ->select('COUNT(grant.id)')
            ->andWhere('grant.sharedFileId = :sharedFileId')
            ->andWhere('grant.granteeUserId = :granteeUserId')
            ->setParameter('sharedFileId', $sharedFileId)
            ->setParameter('granteeUserId', $granteeUserId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * @brief List active (non-expired) grants for a shared file using the database server clock (matches stored expires_at comparisons).
     * @param int $sharedFileId Shared file identifier.
     * @return array<int, ShareGrant>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findActiveBySharedFile(int $sharedFileId): array
    {
        return $this->createQueryBuilder('grant')
            ->andWhere('grant.sharedFileId = :sid')
            ->andWhere('grant.expiresAt IS NULL OR grant.expiresAt > CURRENT_TIMESTAMP()')
            ->setParameter('sid', $sharedFileId)
            ->orderBy('grant.granteeUserId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief List all grants (active or expired) for a shared file.
     * @param int $sharedFileId Shared file identifier.
     * @return array<int, ShareGrant>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findAllBySharedFile(int $sharedFileId): array
    {
        return $this->findBy(['sharedFileId' => $sharedFileId], ['granteeUserId' => 'ASC']);
    }

    /**
     * @brief List all grants for many shared files (folder subtree bulk).
     * @param array<int, int> $sharedFileIds Shared file identifiers.
     * @return array<int, ShareGrant>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findAllBySharedFileIds(array $sharedFileIds): array
    {
        if ($sharedFileIds === []) {
            return [];
        }

        return $this->createQueryBuilder('g')
            ->andWhere('g.sharedFileId IN (:ids)')
            ->setParameter('ids', $sharedFileIds)
            ->orderBy('g.granteeUserId', 'ASC')
            ->addOrderBy('g.sharedFileId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Delete all grants bound to a shared file identifier.
     * @param int $sharedFileId Shared file identifier.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function deleteBySharedFileId(int $sharedFileId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(ShareGrant::class, 'shareGrant')
            ->where('shareGrant.sharedFileId = :sid')
            ->setParameter('sid', $sharedFileId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Find grantee identifiers for a shared file.
     * @param int $sharedFileId Shared file identifier.
     * @return array<int, int>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findGranteeIdsBySharedFile(int $sharedFileId): array
    {
        $grants = $this->findBy(['sharedFileId' => $sharedFileId]);
        $ids = [];
        foreach ($grants as $grant) {
            if ($grant instanceof ShareGrant) {
                $ids[] = $grant->getGranteeUserId();
            }
        }

        return $ids;
    }

    /**
     * @brief List grantee user identifiers for a shared file with only non-expired friend grants (database server clock).
     * @param int $sharedFileId Shared file identifier.
     * @return array<int, int>
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findActiveGranteeIdsBySharedFile(int $sharedFileId): array
    {
        $grants = $this->findActiveBySharedFile($sharedFileId);
        $ids = [];
        foreach ($grants as $grant) {
            if ($grant instanceof ShareGrant) {
                $ids[] = $grant->getGranteeUserId();
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @brief Whether the friends grant row for one grantee on one shared file is active using MySQL NOW() (matches listing expiry semantics).
     * @param int $sharedFileId Shared file identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function isFriendsGrantActiveAtDatabaseNow(int $sharedFileId, int $granteeUserId): bool
    {
        $sql = 'SELECT COUNT(*) FROM share_grant g
            WHERE g.shared_file_id = :sid AND g.grantee_user_id = :gid
            AND (g.expires_at IS NULL OR g.expires_at > NOW())';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'sid' => $sharedFileId,
            'gid' => $granteeUserId,
        ]) > 0;
    }

    /**
     * @brief Delete a single grant tuple.
     * @param int $sharedFileId Shared file identifier.
     * @param int $granteeUserId Grantee identifier.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function deletePair(int $sharedFileId, int $granteeUserId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(ShareGrant::class, 'shareGrant')
            ->where('shareGrant.sharedFileId = :sid')
            ->andWhere('shareGrant.granteeUserId = :gid')
            ->setParameter('sid', $sharedFileId)
            ->setParameter('gid', $granteeUserId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief List distinct grantee user identifiers for files owned by a user.
     * @param int $ownerUserId Owner user identifier.
     * @return array<int, int>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findDistinctGranteeIdsForOwner(int $ownerUserId): array
    {
        $column = $this->createQueryBuilder('g')
            ->select('g.granteeUserId')
            ->distinct()
            ->join(SharedFile::class, 'sf', 'WITH', 'sf.id = g.sharedFileId')
            ->andWhere('sf.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->orderBy('g.granteeUserId', 'ASC')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (mixed $id): int => (int) $id, $column);
    }

    /**
     * @brief Whether any friends grant row exists for a grantee under an owner folder subtree (including expired).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers in the subtree.
     * @param int $granteeUserId Grantee user identifier.
     * @param int|null $excludeSharedFileId Optional shared file id to exclude.
     * @return bool
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function hasAnyGrantForOwnerFolderSubtreeGrantee(int $ownerUserId, array $folderIds, int $granteeUserId, ?int $excludeSharedFileId = null): bool
    {
        if ($folderIds === []) {
            return false;
        }

        $qb = $this->createQueryBuilder('g')
            ->select('COUNT(g.id)')
            ->innerJoin(SharedFile::class, 'sf', 'WITH', 'sf.id = g.sharedFileId')
            ->andWhere('g.granteeUserId = :gid')
            ->andWhere('sf.ownerUserId = :owner')
            ->andWhere('sf.folder IN (:folderIds)')
            ->setParameter('gid', $granteeUserId)
            ->setParameter('owner', $ownerUserId)
            ->setParameter('folderIds', $folderIds);
        if ($excludeSharedFileId !== null) {
            $qb->andWhere('g.sharedFileId != :ex')->setParameter('ex', $excludeSharedFileId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * @brief Return one active friends grant for a grantee under an owner folder subtree (template for expiry on new uploads).
     * @param int $ownerUserId Owner user identifier.
     * @param array<int, int> $folderIds Folder identifiers in the subtree.
     * @param int $granteeUserId Grantee user identifier.
     * @param int|null $excludeSharedFileId Optional shared file id to exclude.
     * @return ShareGrant|null
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findOneActiveGrantForOwnerFolderSubtreeGrantee(int $ownerUserId, array $folderIds, int $granteeUserId, ?int $excludeSharedFileId = null): ?ShareGrant
    {
        if ($folderIds === []) {
            return null;
        }

        $qb = $this->createQueryBuilder('g')
            ->innerJoin(SharedFile::class, 'sf', 'WITH', 'sf.id = g.sharedFileId')
            ->andWhere('g.granteeUserId = :gid')
            ->andWhere('sf.ownerUserId = :owner')
            ->andWhere('sf.folder IN (:folderIds)')
            ->andWhere('g.expiresAt IS NULL OR g.expiresAt > CURRENT_TIMESTAMP()')
            ->setParameter('gid', $granteeUserId)
            ->setParameter('owner', $ownerUserId)
            ->setParameter('folderIds', $folderIds)
            ->setMaxResults(1);
        if ($excludeSharedFileId !== null) {
            $qb->andWhere('g.sharedFileId != :ex')->setParameter('ex', $excludeSharedFileId);
        }

        $result = $qb->getQuery()->getOneOrNullResult();

        return $result instanceof ShareGrant ? $result : null;
    }
}
