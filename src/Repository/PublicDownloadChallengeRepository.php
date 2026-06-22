<?php

namespace App\Repository;

use App\Entity\PublicDownloadChallenge;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository PublicDownloadChallengeRepository.
 *
 * @extends ServiceEntityRepository<PublicDownloadChallenge>
 */
class PublicDownloadChallengeRepository extends ServiceEntityRepository
{
    /**
     * @brief Build public download challenge repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-04-24
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PublicDownloadChallenge::class);
    }

    /**
     * @brief Find latest challenge by token and email.
     * @param string $publicToken Public token.
     * @param string $email Target email.
     * @return PublicDownloadChallenge|null
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function findLatestByTokenAndEmail(string $publicToken, string $email): ?PublicDownloadChallenge
    {
        return $this->createQueryBuilder('challenge')
            ->andWhere('challenge.publicToken = :publicToken')
            ->andWhere('challenge.email = :email')
            ->setParameter('publicToken', $publicToken)
            ->setParameter('email', strtolower(trim($email)))
            ->orderBy('challenge.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @brief Find challenge by identifier.
     * @param int $challengeId Challenge identifier.
     * @return PublicDownloadChallenge|null
     * @date 2026-04-26
     * @author Stephane H.
     */
    public function findOneById(int $challengeId): ?PublicDownloadChallenge
    {
        return $this->find($challengeId);
    }

    /**
     * @brief Delete challenges associated with a public token.
     * @param string $publicToken Public file token.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function deleteByPublicToken(string $publicToken): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(PublicDownloadChallenge::class, 'challenge')
            ->where('challenge.publicToken = :t')
            ->setParameter('t', $publicToken)
            ->getQuery()
            ->execute();
    }
}
