<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FolderAncestor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FolderAncestor>
 */
class FolderAncestorRepository extends ServiceEntityRepository
{
    /**
     * @brief Build folder ancestor repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FolderAncestor::class);
    }

    /**
     * @brief Delete all closure rows for one folder descendant.
     * @param int $folderId Folder identifier.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function deleteByFolderId(int $folderId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(FolderAncestor::class, 'fa')
            ->where('fa.folderId = :folderId')
            ->setParameter('folderId', $folderId)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Delete closure rows for every descendant folder in a subtree rooted at the given folder.
     * @param int $rootFolderId Root folder identifier.
     * @return void
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function deleteBySubtreeRoot(int $rootFolderId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE fa FROM folder_ancestor fa
             INNER JOIN folder_ancestor root ON root.folder_id = fa.folder_id
             WHERE root.ancestor_folder_id = :rootFolderId',
            ['rootFolderId' => $rootFolderId]
        );
    }

    /**
     * @brief List descendant folder ids for one ancestor folder.
     * @param int $ancestorFolderId Ancestor folder identifier.
     * @return list<int>
     * @date 2026-06-26
     * @author Stephane H.
     */
    public function findDescendantFolderIds(int $ancestorFolderId): array
    {
        $rows = $this->createQueryBuilder('fa')
            ->select('fa.folderId')
            ->andWhere('fa.ancestorFolderId = :ancestorFolderId')
            ->setParameter('ancestorFolderId', $ancestorFolderId)
            ->getQuery()
            ->getSingleColumnResult();

        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $rows)));
    }
}
