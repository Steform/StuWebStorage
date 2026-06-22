<?php

namespace App\Repository;

use App\Entity\Folder;
use App\Entity\ShareGrant;
use App\Entity\SharedFile;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository FolderRepository.
 *
 * @extends ServiceEntityRepository<Folder>
 */
class FolderRepository extends ServiceEntityRepository
{
    /**
     * @brief Build folder repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Folder::class);
    }

    /**
     * @brief List child folders for an owner and parent folder.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder|null $parent Parent folder or null for root.
     * @return array<int, Folder>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function findChildrenForOwner(int $ownerUserId, ?Folder $parent): array
    {
        $qb = $this->createQueryBuilder('f')
            ->andWhere('f.ownerUserId = :owner')
            ->setParameter('owner', $ownerUserId)
            ->orderBy('f.nameNormalized', 'ASC')
            ->addOrderBy('f.id', 'ASC');

        if ($parent instanceof Folder) {
            $qb->andWhere('f.parent = :parent')->setParameter('parent', $parent);
        } else {
            $qb->andWhere('f.parent IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief Find folder by owner, parent and normalized name.
     * @param int $ownerUserId Owner user identifier.
     * @param Folder|null $parent Parent folder or null for root.
     * @param string $nameNormalized Lower-cased normalized name.
     * @return Folder|null
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function findOneByOwnerParentAndNormalizedName(int $ownerUserId, ?Folder $parent, string $nameNormalized): ?Folder
    {
        if ($parent instanceof Folder) {
            return $this->findOneBy([
                'ownerUserId' => $ownerUserId,
                'parent' => $parent,
                'nameNormalized' => $nameNormalized,
            ]);
        }

        return $this->findOneBy([
            'ownerUserId' => $ownerUserId,
            'parent' => null,
            'nameNormalized' => $nameNormalized,
        ]);
    }

    /**
     * @brief Resolve folder by anonymous public landing token.
     * @param string $publicFolderToken Public folder token (hex).
     * @return Folder|null
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function findOneByPublicFolderToken(string $publicFolderToken): ?Folder
    {
        return $this->findOneBy(['publicFolderToken' => $publicFolderToken]);
    }

    /**
     * @brief List folders shared with a grantee through folder-level friends intents (includes empty folders).
     * @param int $granteeUserId Grantee user identifier.
     * @return array<int, Folder>
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function findFriendsSharedFoldersForGrantee(int $granteeUserId): array
    {
        if ($granteeUserId <= 0) {
            return [];
        }

        /** @var array<int, Folder> $candidates */
        $candidates = $this->createQueryBuilder('f')
            ->andWhere('f.friendsShareUserIds IS NOT NULL')
            ->orderBy('f.nameNormalized', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($candidates as $folder) {
            if (!$folder instanceof Folder) {
                continue;
            }
            if ((int) $folder->getOwnerUserId() === $granteeUserId) {
                continue;
            }
            $grantees = $folder->getFriendsShareUserIds();
            if (\in_array($granteeUserId, $grantees, true)) {
                $rows[] = $folder;
            }
        }

        return $rows;
    }

    /**
     * @brief List folders that are effectively shared with a grantee through at least one active friends grant on an active file.
     * @param int $granteeUserId Grantee user identifier.
     * @return array<int, Folder>
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function findActiveFriendsSharedFoldersForGrantee(int $granteeUserId): array
    {
        if ($granteeUserId <= 0) {
            return [];
        }

        /** @var array<int, Folder> $rows */
        $rows = $this->createQueryBuilder('f')
            ->distinct()
            ->innerJoin(SharedFile::class, 'sf', 'WITH', 'sf.folder = f')
            ->innerJoin(ShareGrant::class, 'sg', 'WITH', 'sg.sharedFileId = sf.id')
            ->andWhere('sg.granteeUserId = :granteeUserId')
            ->andWhere('sg.expiresAt IS NULL OR sg.expiresAt > CURRENT_TIMESTAMP()')
            ->andWhere('sf.expiresAt IS NULL OR sf.expiresAt > CURRENT_TIMESTAMP()')
            ->andWhere('sf.ownerUserId != :granteeUserId')
            ->setParameter('granteeUserId', $granteeUserId)
            ->orderBy('f.nameNormalized', 'ASC')
            ->addOrderBy('f.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
