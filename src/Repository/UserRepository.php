<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Class UserRepository.
 *
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    /**
     * @brief Build user repository.
     * @param ManagerRegistry $registry Doctrine registry.
     * @return void
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    /**
     * @brief Paginate users with optional search query.
     * @param int $page Current page number.
     * @param int $pageSize Page size.
     * @param string $searchTerm Optional search term.
     * @return array{items: list<User>, total: int}
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function paginateUsers(int $page, int $pageSize, string $searchTerm = ''): array
    {
        $safePage = max(1, $page);
        $safePageSize = max(1, min($pageSize, 200));
        $queryBuilder = $this->createQueryBuilder('app_user')
            ->orderBy('app_user.id', 'DESC');

        $normalizedSearch = trim($searchTerm);
        if ($normalizedSearch !== '') {
            $queryBuilder
                ->andWhere('app_user.email LIKE :search OR app_user.pseudonym LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        $items = $queryBuilder
            ->setFirstResult(($safePage - 1) * $safePageSize)
            ->setMaxResults($safePageSize)
            ->getQuery()
            ->getResult();

        $countBuilder = $this->createQueryBuilder('app_user')
            ->select('COUNT(app_user.id)');
        if ($normalizedSearch !== '') {
            $countBuilder
                ->andWhere('app_user.email LIKE :search OR app_user.pseudonym LIKE :search')
                ->setParameter('search', '%'.$normalizedSearch.'%');
        }

        return [
            'items' => $items,
            'total' => (int) $countBuilder->getQuery()->getSingleScalarResult(),
        ];
    }

    /**
     * @brief Count active administrators.
     * @param void No input parameter.
     * @return int
     * @date 2026-04-23
     * @author Stephane H.
     */
    public function countActiveAdmins(): int
    {
        return (int) $this->createQueryBuilder('app_user')
            ->select('COUNT(app_user.id)')
            ->andWhere('app_user.active = :active')
            ->andWhere('JSON_CONTAINS(app_user.roles, :adminRole) = 1')
            ->setParameter('active', true)
            ->setParameter('adminRole', '["ROLE_ADMIN"]')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Load users by primary key for small in-list UIs (file filter grantees).
     * @param array<int, int> $userIds User identifiers.
     * @return array<int, User>
     * @date 2026-04-27
     * @author Stephane H.
     */
    public function findByIdsOrdered(array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $userIds),
            static fn (int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }

        return $this->createQueryBuilder('app_user')
            ->where('app_user.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('app_user.pseudonym', 'ASC')
            ->addOrderBy('app_user.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Find active users for private-share grant autocomplete (pseudo or email match, ranked, capped).
     * @param int $actorUserId Current actor identifier (kept for API compatibility; not used for exclusion).
     * @param string $query Search string (minimum two characters after trim; enforced by caller).
     * @param int $limit Maximum rows to return (default five).
     * @return list<User>
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function searchActiveUsersForGrantSuggest(int $actorUserId, string $query, int $limit = 5): array
    {
        unset($actorUserId);
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) < 2) {
            return [];
        }

        $safeLimit = max(1, min($limit, 50));
        $likeAny = '%'.$normalized.'%';

        $candidates = $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->andWhere('(u.roles LIKE :roleShare OR u.roles LIKE :roleAdmin)')
            ->andWhere(
                '(LOWER(u.pseudonym) LIKE :likeAny AND u.pseudonym <> :emptyPseudo) OR (u.pseudonym = :emptyPseudo AND LOWER(u.email) LIKE :likeAny)'
            )
            ->setParameter('active', true)
            ->setParameter('roleShare', '%"ROLE_SHARE"%')
            ->setParameter('roleAdmin', '%"ROLE_ADMIN"%')
            ->setParameter('likeAny', $likeAny)
            ->setParameter('emptyPseudo', '')
            ->setMaxResults(80)
            ->getQuery()
            ->getResult();

        usort($candidates, static function (User $a, User $b) use ($normalized): int {
            $ra = self::grantSuggestRank($a, $normalized);
            $rb = self::grantSuggestRank($b, $normalized);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }

            $pa = mb_strtolower($a->getPseudonym());
            $pb = mb_strtolower($b->getPseudonym());
            if ($pa !== '' && $pb !== '') {
                return strcmp($pa, $pb);
            }
            if ($pa !== '') {
                return -1;
            }
            if ($pb !== '') {
                return 1;
            }

            return strcmp(mb_strtolower($a->getEmail()), mb_strtolower($b->getEmail()));
        });

        return array_slice($candidates, 0, $safeLimit);
    }

    /**
     * @brief Rank a user match for grant suggest ordering (lower is better).
     * @param User $user Candidate user.
     * @param string $normalized Lowercase trimmed query (length at least two).
     * @return int
     * @date 2026-04-27
     * @author Stephane H.
     */
    private static function grantSuggestRank(User $user, string $normalized): int
    {
        $p = mb_strtolower($user->getPseudonym());
        $e = mb_strtolower($user->getEmail());
        if ($p !== '') {
            if (str_starts_with($p, $normalized)) {
                return 0;
            }
            if (str_contains($p, $normalized)) {
                return 4;
            }
        }
        if (str_starts_with($e, $normalized)) {
            return 1;
        }
        if (str_contains($e, $normalized)) {
            return 5;
        }

        return 99;
    }

    /**
     * @brief Parse admin owner search input into pseudo and email tokens (handles displayed label "pseudo (email)").
     * @param string $raw Raw query string.
     * @return array{pseudo: string, email: string} Lowercased trimmed tokens; when not compound, both equal the full normalized string.
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function parseAdminOwnerSearchTokens(string $raw): array
    {
        $trimmed = trim($raw);
        $normalized = mb_strtolower($trimmed);
        if ($normalized === '') {
            return ['pseudo' => '', 'email' => ''];
        }
        if (preg_match('/^(.+?)\s+\(([^)]+)\)\s*$/u', $trimmed, $m) === 1) {
            $emailPart = trim($m[2]);
            if ($emailPart !== '' && str_contains($emailPart, '@')) {
                return [
                    'pseudo' => mb_strtolower(trim($m[1])),
                    'email' => mb_strtolower($emailPart),
                ];
            }
        }

        return ['pseudo' => $normalized, 'email' => $normalized];
    }

    /**
     * @brief Extract the pseudonym segment from admin owner resolve input (strip trailing " (email)" when present).
     * @param string $raw Raw query string.
     * @return string Trimmed pseudo segment (original casing preserved for display; caller lowercases for SQL).
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function extractPseudoSegmentForAdminOwnerResolve(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }
        if (preg_match('/^(.+?)\s+\(([^)]+)\)\s*$/u', $trimmed, $m) === 1) {
            $emailPart = trim($m[2]);
            if ($emailPart !== '' && str_contains($emailPart, '@')) {
                return trim($m[1]);
            }
        }

        return $trimmed;
    }

    /**
     * @brief Find active users matching exact pseudonym (case-insensitive), capped for ambiguity detection.
     * @param string $pseudoExact Pseudonym or leading segment before " (" in compound label.
     * @param int $cap Maximum rows to load (default three).
     * @return list<User>
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function findActiveUsersMatchingExactPseudo(string $pseudoExact, int $cap = 3): array
    {
        $p = mb_strtolower(trim($pseudoExact));
        if ($p === '') {
            return [];
        }
        $safeCap = max(1, min($cap, 10));

        return $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->andWhere('(u.roles LIKE :roleShare OR u.roles LIKE :roleAdmin)')
            ->andWhere('LOWER(TRIM(u.pseudonym)) = :pExact')
            ->setParameter('active', true)
            ->setParameter('roleShare', '%"ROLE_SHARE"%')
            ->setParameter('roleAdmin', '%"ROLE_ADMIN"%')
            ->setParameter('pExact', $p)
            ->orderBy('u.id', 'ASC')
            ->setMaxResults($safeCap)
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Find active users for admin owner selection autocomplete (pseudo/email).
     * @param string $query Search string.
     * @param int $limit Maximum rows to return.
     * @return list<User>
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function searchActiveUsersForAdminOwnerSuggest(string $query, int $limit = 10): array
    {
        $normalized = mb_strtolower(trim($query));
        if (mb_strlen($normalized) < 1) {
            return [];
        }

        $safeLimit = max(1, min($limit, 50));
        $tokens = $this->parseAdminOwnerSearchTokens($query);
        $likeAny = '%'.$normalized.'%';
        $qb = $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->andWhere('(u.roles LIKE :roleShare OR u.roles LIKE :roleAdmin)')
            ->setParameter('active', true)
            ->setParameter('roleShare', '%"ROLE_SHARE"%')
            ->setParameter('roleAdmin', '%"ROLE_ADMIN"%');

        if ($tokens['pseudo'] !== $tokens['email']) {
            $qb->andWhere(
                '(LOWER(TRIM(u.pseudonym)) = :pExact AND LOWER(TRIM(u.email)) = :eExact) OR (LOWER(u.pseudonym) LIKE :likeAny OR LOWER(u.email) LIKE :likeAny)'
            )
                ->setParameter('pExact', $tokens['pseudo'])
                ->setParameter('eExact', $tokens['email'])
                ->setParameter('likeAny', $likeAny);
        } else {
            $qb->andWhere('LOWER(u.pseudonym) LIKE :likeAny OR LOWER(u.email) LIKE :likeAny')
                ->setParameter('likeAny', $likeAny);
        }

        return $qb
            ->orderBy('u.pseudonym', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief List active users that contain a role token in the serialized roles payload.
     * @param int $excludeUserId User id to exclude.
     * @param string $roleToken Role token to search (for example ROLE_SHARE).
     * @return list<User>
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function findActiveUsersByRoleTokenLike(int $excludeUserId, string $roleToken): array
    {
        $token = trim($roleToken);
        if ($token === '') {
            return [];
        }

        return $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->andWhere('u.id <> :excludeUserId')
            ->andWhere('u.roles LIKE :roleLike')
            ->setParameter('active', true)
            ->setParameter('excludeUserId', $excludeUserId)
            ->setParameter('roleLike', '%"'.$token.'"%')
            ->orderBy('u.pseudonym', 'ASC')
            ->addOrderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Count active users having a role token in their serialized roles payload.
     * @param int $excludeUserId User id to exclude.
     * @param string $roleToken Role token to search (for example ROLE_SHARE).
     * @return int
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function countActiveUsersByRoleTokenLike(int $excludeUserId, string $roleToken): int
    {
        $token = trim($roleToken);
        if ($token === '') {
            return 0;
        }

        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.active = :active')
            ->andWhere('u.id <> :excludeUserId')
            ->andWhere('u.roles LIKE :roleLike')
            ->setParameter('active', true)
            ->setParameter('excludeUserId', $excludeUserId)
            ->setParameter('roleLike', '%"'.$token.'"%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @brief Paginated list of active users with a role token (stable sort by pseudonym or id).
     * @param int $excludeUserId User id to exclude.
     * @param string $roleToken Role token to search (for example ROLE_SHARE).
     * @param string $sortField Either pseudo or id.
     * @param string $sortDirection asc or desc.
     * @param int $limit Page size (minimum 1).
     * @param int $offset Zero-based offset.
     * @return list<User>
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function findActiveUsersByRoleTokenLikePaginated(
        int $excludeUserId,
        string $roleToken,
        string $sortField,
        string $sortDirection,
        int $limit,
        int $offset,
    ): array {
        $token = trim($roleToken);
        if ($token === '') {
            return [];
        }

        $safeLimit = max(1, $limit);
        $safeOffset = max(0, $offset);
        $dir = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';

        $qb = $this->createQueryBuilder('u')
            ->where('u.active = :active')
            ->andWhere('u.id <> :excludeUserId')
            ->andWhere('u.roles LIKE :roleLike')
            ->setParameter('active', true)
            ->setParameter('excludeUserId', $excludeUserId)
            ->setParameter('roleLike', '%"'.$token.'"%');

        if ($sortField === 'id') {
            $qb->orderBy('u.id', $dir);
        } else {
            $qb->orderBy('u.pseudonym', $dir)
                ->addOrderBy('u.email', $dir)
                ->addOrderBy('u.id', 'ASC');
        }

        return $qb->setFirstResult($safeOffset)
            ->setMaxResults($safeLimit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @brief Whether the user exists, is active, and has ROLE_SHARE or ROLE_ADMIN (admin proxy uploads/folders).
     * @param int $userId User primary key.
     * @return bool
     * @date 2026-05-07
     * @author Stephane H.
     */
    public function isActiveUserWithShareOrAdminRole(int $userId): bool
    {
        if ($userId < 1) {
            return false;
        }
        $user = $this->find($userId);
        if (!$user instanceof User || !$user->isActive()) {
            return false;
        }

        return \in_array('ROLE_SHARE', $user->getRoles(), true) || \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
