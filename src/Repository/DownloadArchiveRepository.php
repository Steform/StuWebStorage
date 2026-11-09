<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DownloadArchive;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @brief Persistence for virtual download archives.
 * @extends ServiceEntityRepository<DownloadArchive>
 * @date 2026-11-02
 * @author Stephane H.
 */
class DownloadArchiveRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadArchive::class);
    }

    /**
     * @brief Find one archive by public token.
     * @param string $token Archive token.
     * @return DownloadArchive|null
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function findOneByToken(string $token): ?DownloadArchive
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * @brief List recent archives for one actor.
     * @param string $actorType Actor type.
     * @param string $actorId Actor id.
     * @param int $limit Max rows.
     * @return list<DownloadArchive>
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function findRecentForActor(string $actorType, string $actorId, int $limit = 20): array
    {
        /** @var list<DownloadArchive> $rows */
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.actorType = :type')
            ->andWhere('a.actorId = :id')
            ->andWhere('a.status != :expired')
            ->setParameter('type', $actorType)
            ->setParameter('id', $actorId)
            ->setParameter('expired', DownloadArchive::STATUS_EXPIRED)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /**
     * @brief Persist archive.
     * @param DownloadArchive $archive Archive entity.
     * @param bool $flush Whether to flush immediately.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function save(DownloadArchive $archive, bool $flush = true): void
    {
        $this->getEntityManager()->persist($archive);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @brief Remove archive row.
     * @param DownloadArchive $archive Archive entity.
     * @param bool $flush Whether to flush.
     * @return void
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function remove(DownloadArchive $archive, bool $flush = true): void
    {
        $this->getEntityManager()->remove($archive);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * @brief Delete expired archives and return count.
     * @param \DateTimeImmutable $now Reference instant.
     * @return int
     * @date 2026-11-02
     * @author Stephane H.
     */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.expiresAt <= :now OR a.status = :expired')
            ->setParameter('now', $now)
            ->setParameter('expired', DownloadArchive::STATUS_EXPIRED)
            ->getQuery()
            ->execute();
    }
}
