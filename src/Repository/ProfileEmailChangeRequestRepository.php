<?php

namespace App\Repository;

use App\Entity\ProfileEmailChangeRequest;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class ProfileEmailChangeRequestRepository.
 *
 * @extends ServiceEntityRepository<ProfileEmailChangeRequest>
 */
class ProfileEmailChangeRequestRepository extends ServiceEntityRepository
{
    /**
     * @brief Build profile email change request repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProfileEmailChangeRequest::class);
    }

    /**
     * @brief Find latest active request by user.
     * @param int $userId User identifier.
     * @param DateTimeImmutable $now Current datetime.
     * @return ProfileEmailChangeRequest|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findLatestActiveByUserId(int $userId, DateTimeImmutable $now): ?ProfileEmailChangeRequest
    {
        return $this->createQueryBuilder('profile_email_change_request')
            ->andWhere('profile_email_change_request.userId = :userId')
            ->andWhere('profile_email_change_request.consumed = :consumed')
            ->andWhere('profile_email_change_request.expiresAt >= :now')
            ->setParameter('userId', $userId)
            ->setParameter('consumed', false)
            ->setParameter('now', $now)
            ->orderBy('profile_email_change_request.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
