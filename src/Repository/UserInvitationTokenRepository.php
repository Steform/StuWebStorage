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

    /**
     * @brief Find invitation token by hash regardless of expiration.
     * @param string $tokenHash Token hash.
     * @return UserInvitationToken|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findByTokenHash(string $tokenHash): ?UserInvitationToken
    {
        return $this->createQueryBuilder('invitation')
            ->andWhere('invitation.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @brief Find locale of latest pending invitation for one user.
     * @param int $userId Invited user identifier.
     * @return string|null
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findLatestPendingLocaleForUser(int $userId): ?string
    {
        /** @var UserInvitationToken|null $invitation */
        $invitation = $this->createQueryBuilder('invitation')
            ->andWhere('invitation.userId = :userId')
            ->andWhere('invitation.consumedAt IS NULL')
            ->setParameter('userId', $userId)
            ->orderBy('invitation.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$invitation instanceof UserInvitationToken) {
            return null;
        }

        return $invitation->getLocale();
    }

    /**
     * @brief Check whether user still has a pending invitation.
     * @param int $userId Invited user identifier.
     * @return bool
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function hasPendingInvitationForUser(int $userId): bool
    {
        return (int) $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->andWhere('invitation.userId = :userId')
            ->andWhere('invitation.consumedAt IS NULL')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @brief Count invitation tokens linked to one user.
     * @param int $userId Invited user identifier.
     * @return int
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function countByUserId(int $userId): int
    {
        return (int) $this->createQueryBuilder('invitation')
            ->select('COUNT(invitation.id)')
            ->andWhere('invitation.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Return user identifiers with pending invitation among candidates.
     * @param list<int> $userIds Candidate user identifiers.
     * @return list<int>
     * @date 2026-06-24
     * @author Stephane H.
     */
    public function findUserIdsWithPendingInvitation(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('invitation')
            ->select('DISTINCT invitation.userId')
            ->andWhere('invitation.userId IN (:userIds)')
            ->andWhere('invitation.consumedAt IS NULL')
            ->setParameter('userIds', $userIds)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['userId'], $rows);
    }
}
