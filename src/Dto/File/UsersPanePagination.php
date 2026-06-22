<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * @brief Pagination state for admin multi-user (view_scope=all) user list.
 * @date 2026-05-04
 * @author Stephane H.
 */
final readonly class UsersPanePagination
{
    /**
     * @brief Build pagination DTO.
     * @param int $page One-based page index.
     * @param int $pageSize Page size (20, 50, 100, or 200).
     * @param int $totalUsers Total eligible users.
     * @param int $totalPages Total page count (minimum 1).
     * @param string $sortField pseudo or id.
     * @param string $sortDirection asc or desc.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function __construct(
        public int $page,
        public int $pageSize,
        public int $totalUsers,
        public int $totalPages,
        public string $sortField,
        public string $sortDirection,
    ) {
    }
}
