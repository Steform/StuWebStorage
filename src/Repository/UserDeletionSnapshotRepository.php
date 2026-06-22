<?php

namespace App\Repository;

use App\Entity\UserDeletionSnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository UserDeletionSnapshotRepository.
 *
 * @extends ServiceEntityRepository<UserDeletionSnapshot>
 */
class UserDeletionSnapshotRepository extends ServiceEntityRepository
{
    /**
     * @brief Build user deletion snapshot repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-04-28
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserDeletionSnapshot::class);
    }
}
