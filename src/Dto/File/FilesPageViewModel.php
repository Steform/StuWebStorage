<?php

declare(strict_types=1);

namespace App\Dto\File;

/**
 * @brief Full files index page: panes, URL scope, optional user-list pagination.
 * @date 2026-05-04
 * @author Stephane H.
 */
final readonly class FilesPageViewModel
{
    /**
     * @brief Construct page view model.
     * @param string $canonicalViewScope me, user, or all.
     * @param string $listingScope owned, shared, or both.
     * @param list<UserFilesPaneViewModel> $panes Rendered user panes (one or many).
     * @param string|null $selectionFocusPaneId Optional pane id from query (pane=).
     * @param UsersPanePagination|null $usersPagination Set when view_scope=all.
     * @param string $usersSortField pseudo or id.
     * @param string $usersSortDirection asc or desc.
     * @return void
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function __construct(
        public string $canonicalViewScope,
        public string $listingScope,
        public array $panes,
        public ?string $selectionFocusPaneId,
        public ?UsersPanePagination $usersPagination,
        public string $usersSortField = 'pseudo',
        public string $usersSortDirection = 'asc',
    ) {
    }
}
