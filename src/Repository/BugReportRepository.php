<?php

namespace App\Repository;

use App\Entity\BugReport;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository BugReportRepository.
 *
 * @extends ServiceEntityRepository<BugReport>
 */
class BugReportRepository extends ServiceEntityRepository
{
    /**
     * @brief Build bug report repository.
     * @param ManagerRegistry $registry Doctrine manager registry.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BugReport::class);
    }

    /**
     * @brief Find bug reports with admin filters.
     * @param string $statusFilter Status filter.
     * @param string $severityFilter Severity filter.
     * @param string $routeFilter Route filter.
     * @param \DateTimeImmutable|null $fromDate Start datetime filter.
     * @param \DateTimeImmutable|null $toDate End datetime filter.
     * @param bool $includeArchived Whether archived reports are included.
     * @param int $limit Maximum row count.
     * @return array<int, BugReport>
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function findForAdminList(
        string $statusFilter,
        string $severityFilter,
        string $routeFilter,
        ?\DateTimeImmutable $fromDate,
        ?\DateTimeImmutable $toDate,
        bool $includeArchived,
        int $limit = 200
    ): array {
        $qb = $this->createQueryBuilder('bug')
            ->leftJoin('bug.reporterUser', 'reporter')
            ->addSelect('reporter')
            ->orderBy('bug.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($statusFilter !== '') {
            $qb->andWhere('bug.status = :statusFilter')
                ->setParameter('statusFilter', $statusFilter);
        }

        if ($severityFilter !== '') {
            $qb->andWhere('bug.severity = :severityFilter')
                ->setParameter('severityFilter', $severityFilter);
        }

        if ($routeFilter !== '') {
            $qb->andWhere('bug.routeName = :routeFilter')
                ->setParameter('routeFilter', $routeFilter);
        }

        if ($fromDate instanceof \DateTimeImmutable) {
            $qb->andWhere('bug.createdAt >= :fromDate')
                ->setParameter('fromDate', $fromDate);
        }

        if ($toDate instanceof \DateTimeImmutable) {
            $qb->andWhere('bug.createdAt <= :toDate')
                ->setParameter('toDate', $toDate);
        }

        if (!$includeArchived) {
            $qb->andWhere('bug.archivedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @brief Persist bug report aggregate.
     * @param BugReport $bugReport Bug report aggregate.
     * @return void
     * @date 2026-05-06
     * @author Stephane H.
     */
    public function save(BugReport $bugReport): void
    {
        $entityManager = $this->getEntityManager();
        $entityManager->persist($bugReport);
        $entityManager->flush();
    }
}
