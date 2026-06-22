<?php

namespace App\Repository;

use App\Entity\LoginTotpChallenge;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class LoginTotpChallengeRepository.
 *
 * @extends ServiceEntityRepository<LoginTotpChallenge>
 */
class LoginTotpChallengeRepository extends ServiceEntityRepository
{
    /**
     * @brief Build repository for login TOTP challenges.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginTotpChallenge::class);
    }

    /**
     * @brief Find latest active challenge for identity.
     * @param string $identity Login identity.
     * @param DateTimeImmutable $now Current datetime.
     * @return LoginTotpChallenge|null
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function findLatestActiveByIdentity(string $identity, DateTimeImmutable $now): ?LoginTotpChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.identity = :identity')
            ->andWhere('challenge.consumedAt IS NULL')
            ->andWhere('challenge.expiresAt >= :now')
            ->setParameter('identity', $identity)
            ->setParameter('now', $now)
            ->orderBy('challenge.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @brief Find latest unconsumed challenge for identity.
     * @param string $identity Login identity.
     * @return LoginTotpChallenge|null
     * @date 2026-06-15
     * @author Stephane H.
     */
    public function findLatestPendingByIdentity(string $identity): ?LoginTotpChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.identity = :identity')
            ->andWhere('challenge.consumedAt IS NULL')
            ->setParameter('identity', $identity)
            ->orderBy('challenge.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
