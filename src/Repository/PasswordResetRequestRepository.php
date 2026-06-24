<?php

namespace App\Repository;

use App\Entity\PasswordResetRequest;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class PasswordResetRequestRepository.
 *
 * @extends ServiceEntityRepository<PasswordResetRequest>
 */
class PasswordResetRequestRepository extends ServiceEntityRepository
{
    /**
     * @brief Build password reset request repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetRequest::class);
    }

    /**
     * @brief Find active reset request by token hash.
     * @param string $tokenHash Token hash.
     * @param DateTimeImmutable $now Current datetime.
     * @return PasswordResetRequest|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findActiveByTokenHash(string $tokenHash, DateTimeImmutable $now): ?PasswordResetRequest
    {
        return $this->createQueryBuilder('resetRequest')
            ->andWhere('resetRequest.token = :tokenHash')
            ->andWhere('resetRequest.consumed = false')
            ->andWhere('resetRequest.expiresAt >= :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @brief Find reset request by token hash regardless of expiration.
     * @param string $tokenHash Token hash.
     * @return PasswordResetRequest|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findByTokenHash(string $tokenHash): ?PasswordResetRequest
    {
        return $this->createQueryBuilder('resetRequest')
            ->andWhere('resetRequest.token = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
