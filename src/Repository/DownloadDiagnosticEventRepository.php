<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DownloadDiagnosticEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DownloadDiagnosticEvent>
 */
final class DownloadDiagnosticEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DownloadDiagnosticEvent::class);
    }

    /**
     * @brief Persist one diagnostic event row.
     * @param DownloadDiagnosticEvent $event Diagnostic event entity.
     * @param bool $flush Whether to flush immediately.
     * @return void
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function save(DownloadDiagnosticEvent $event, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($event);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * @brief Search diagnostic events with optional filters.
     * @param array<string, mixed> $filters Filter map.
     * @param int $limit Max rows.
     * @param int $offset Pagination offset.
     * @return array<int, DownloadDiagnosticEvent>
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function search(array $filters, int $limit = 200, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.createdAt', 'DESC')
            ->setMaxResults(max(1, min(1000, $limit)))
            ->setFirstResult(max(0, $offset));

        if (is_string($filters['downloadId'] ?? null) && $filters['downloadId'] !== '') {
            $qb->andWhere('e.downloadId = :downloadId')->setParameter('downloadId', $filters['downloadId']);
        }
        if (is_string($filters['phase'] ?? null) && $filters['phase'] !== '') {
            $qb->andWhere('e.phase = :phase')->setParameter('phase', $filters['phase']);
        }
        if (is_string($filters['status'] ?? null) && $filters['status'] !== '') {
            $qb->andWhere('e.status = :status')->setParameter('status', $filters['status']);
        }
        if (is_numeric($filters['sharedFileId'] ?? null)) {
            $qb->andWhere('e.sharedFileId = :sharedFileId')->setParameter('sharedFileId', (int) $filters['sharedFileId']);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief Delete events older than provided cutoff datetime.
     * @param \DateTimeImmutable $cutoff Remove rows older than cutoff.
     * @return int Deleted row count.
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): int
    {
        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.createdAt < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }

    /**
     * @brief Return full timeline ordered ascending for one download id.
     * @param string $downloadId Correlation identifier.
     * @return array<int, DownloadDiagnosticEvent>
     * @date 2026-07-07
     * @author Stephane H.
     */
    public function findTimeline(string $downloadId): array
    {
        return $this->createQueryBuilder('e')
            ->where('e.downloadId = :downloadId')
            ->setParameter('downloadId', $downloadId)
            ->orderBy('e.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
