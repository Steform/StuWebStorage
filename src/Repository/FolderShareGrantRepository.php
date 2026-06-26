<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FolderShareGrant;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FolderShareGrant>
 */
class FolderShareGrantRepository extends ServiceEntityRepository
{
    /**
     * @brief Build folder share grant repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FolderShareGrant::class);
    }

    /**
     * @brief List active folder grants for one grantee.
     * @param int $granteeUserId Grantee user identifier.
     * @return array<int, FolderShareGrant>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findActiveByGrantee(int $granteeUserId): array
    {
        return $this->createQueryBuilder('fsg')
            ->andWhere('fsg.granteeUserId = :granteeUserId')
            ->andWhere('fsg.expiresAt IS NULL OR fsg.expiresAt > CURRENT_TIMESTAMP()')
            ->setParameter('granteeUserId', $granteeUserId)
            ->orderBy('fsg.folderId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief List active folder grants on one folder.
     * @param int $folderId Folder identifier.
     * @return array<int, FolderShareGrant>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findActiveByFolder(int $folderId): array
    {
        return $this->createQueryBuilder('fsg')
            ->andWhere('fsg.folderId = :folderId')
            ->andWhere('fsg.expiresAt IS NULL OR fsg.expiresAt > CURRENT_TIMESTAMP()')
            ->setParameter('folderId', $folderId)
            ->orderBy('fsg.granteeUserId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief List all folder grants on one folder (active or expired).
     * @param int $folderId Folder identifier.
     * @return array<int, FolderShareGrant>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findAllByFolder(int $folderId): array
    {
        return $this->findBy(['folderId' => $folderId], ['granteeUserId' => 'ASC']);
    }

    /**
     * @brief Find one folder grant tuple.
     * @param int $folderId Folder identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @return FolderShareGrant|null
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findOneByFolderAndGrantee(int $folderId, int $granteeUserId): ?FolderShareGrant
    {
        $grant = $this->findOneBy(['folderId' => $folderId, 'granteeUserId' => $granteeUserId]);

        return $grant instanceof FolderShareGrant ? $grant : null;
    }

    /**
     * @brief Whether an active folder grant covers a file folder through folder_ancestor.
     * @param int $granteeUserId Grantee user identifier.
     * @param int $fileFolderId File folder identifier.
     * @return bool
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function hasActiveGrantForFileFolder(int $granteeUserId, int $fileFolderId): bool
    {
        if ($granteeUserId < 1 || $fileFolderId < 1) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM folder_share_grant fsg
            INNER JOIN folder_ancestor fa ON fa.ancestor_folder_id = fsg.folder_id
            WHERE fa.folder_id = :fileFolderId
              AND fsg.grantee_user_id = :granteeUserId
              AND (fsg.expires_at IS NULL OR fsg.expires_at > NOW())';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'fileFolderId' => $fileFolderId,
            'granteeUserId' => $granteeUserId,
        ]) > 0;
    }

    /**
     * @brief Whether an active folder grant exists on the folder or any ancestor.
     * @param int $granteeUserId Grantee user identifier.
     * @param int $folderId Folder identifier.
     * @return bool
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function hasActiveGrantOnFolderOrAncestor(int $granteeUserId, int $folderId): bool
    {
        return $this->hasActiveGrantForFileFolder($granteeUserId, $folderId);
    }

    /**
     * @brief Whether any active folder grant exists on the folder or any ancestor for any grantee.
     * @param int $folderId Folder identifier.
     * @return bool
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function hasAnyActiveGrantOnFolderOrAncestor(int $folderId): bool
    {
        if ($folderId < 1) {
            return false;
        }

        $sql = 'SELECT COUNT(*) FROM folder_share_grant fsg
            INNER JOIN folder_ancestor fa ON fa.ancestor_folder_id = fsg.folder_id
            WHERE fa.folder_id = :folderId
              AND (fsg.expires_at IS NULL OR fsg.expires_at > NOW())';

        return (int) $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'folderId' => $folderId,
        ]) > 0;
    }

    /**
     * @brief Create or update one folder grant.
     * @param int $folderId Folder identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @param DateTimeImmutable|null $expiresAt Optional expiration instant.
     * @return FolderShareGrant
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function upsert(int $folderId, int $granteeUserId, ?DateTimeImmutable $expiresAt): FolderShareGrant
    {
        $existing = $this->findOneByFolderAndGrantee($folderId, $granteeUserId);
        if ($existing instanceof FolderShareGrant) {
            $existing->setExpiresAt($expiresAt);
            $this->getEntityManager()->flush();

            return $existing;
        }

        $grant = new FolderShareGrant($folderId, $granteeUserId, $expiresAt);
        $this->getEntityManager()->persist($grant);
        $this->getEntityManager()->flush();

        return $grant;
    }

    /**
     * @brief Delete one folder grant tuple.
     * @param int $folderId Folder identifier.
     * @param int $granteeUserId Grantee user identifier.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function deletePair(int $folderId, int $granteeUserId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(FolderShareGrant::class, 'fsg')
            ->where('fsg.folderId = :folderId')
            ->andWhere('fsg.granteeUserId = :granteeUserId')
            ->setParameter('folderId', $folderId)
            ->setParameter('granteeUserId', $granteeUserId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Delete all folder grants bound to one folder.
     * @param int $folderId Folder identifier.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function deleteAllByFolder(int $folderId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(FolderShareGrant::class, 'fsg')
            ->where('fsg.folderId = :folderId')
            ->setParameter('folderId', $folderId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief List distinct grantee ids with at least one active folder grant.
     * @param int $ownerUserId Owner user identifier.
     * @return array<int, int>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findDistinctGranteeIdsForOwner(int $ownerUserId): array
    {
        $sql = 'SELECT DISTINCT fsg.grantee_user_id
            FROM folder_share_grant fsg
            INNER JOIN folder f ON f.id = fsg.folder_id
            WHERE f.owner_user_id = :ownerUserId
            ORDER BY fsg.grantee_user_id ASC';
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, [
            'ownerUserId' => $ownerUserId,
        ]);

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $rows));
    }
}
