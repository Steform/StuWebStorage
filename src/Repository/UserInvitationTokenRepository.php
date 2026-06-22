<?php

namespace App\Repository;

use App\Entity\UserInvitationToken;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class UserInvitationTokenRepository.
 *
 * @extends ServiceEntityRepository<UserInvitationToken>
 */
class UserInvitationTokenRepository extends ServiceEntityRepository
{
    /**
     * @brief Build invitation token repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserInvitationToken::class);
    }

    /**
     * @brief Find active invitation by token hash.
     * @param string $tokenHash Token hash.
     * @param DateTimeImmutable $now Current datetime.
     * @return UserInvitationToken|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findActiveByTokenHash(string $tokenHash, DateTimeImmutable $now): ?UserInvitationToken
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.tokenHash = :tokenHash')
            ->andWhere('invitation.consumedAt IS NULL')
            ->andWhere('invitation.expiresAt >= :now')
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
