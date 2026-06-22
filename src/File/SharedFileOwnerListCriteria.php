<?php

namespace App\File;

use DateTimeImmutable;

/**
 * Request-scoped filters for owner shared file listing (Sprint 16 toolbar + Sprint 17 advanced modal).
 */
readonly final class SharedFileOwnerListCriteria
{
    /**
     * @brief Build normalized listing criteria for repository queries.
     * @param string $searchQuery Optional substring search on name or extension.
     * @param string $sortField Whitelisted sort field key.
     * @param string $sortDirection asc or desc.
     * @param string $filterPublic Empty string for all, yes for public only, no for private only.
     * @param array<int, string> $extensionFilters Lower-case extension tokens allowed by caller.
     * @param string $view list or grid for UI layout (preserved in URLs).
     * @param string $filterHasGrant Empty string for all, yes if at least one ShareGrant exists, no if none.
     * @param array<int, int> $granteeUserIds Grantee user IDs (OR semantics); caller must intersect with eligible IDs.
     * @param DateTimeImmutable|null $uploadedAfter Inclusive lower bound on uploaded_at.
     * @param DateTimeImmutable|null $uploadedBefore Inclusive upper bound on uploaded_at.
     * @param DateTimeImmutable|null $updatedAfter Inclusive lower bound on updated_at.
     * @param DateTimeImmutable|null $updatedBefore Inclusive upper bound on updated_at.
     * @param DateTimeImmutable|null $expiresAfter Inclusive lower bound on expires_at (rows with null expires_at excluded when any expires bound is set).
     * @param DateTimeImmutable|null $expiresBefore Inclusive upper bound on expires_at.
     * @param string $listingScope both for owned and shared sections, owned for mine-only, shared for incoming shares only.
     * @return void
     * @date 2026-05-02
     * @author Stephane H.
     */
    public function __construct(
        public string $searchQuery = '',
        public string $sortField = '',
        public string $sortDirection = '',
        public string $filterPublic = '',
        public array $extensionFilters = [],
        public string $view = 'list',
        public string $filterHasGrant = '',
        public array $granteeUserIds = [],
        public ?DateTimeImmutable $uploadedAfter = null,
        public ?DateTimeImmutable $uploadedBefore = null,
        public ?DateTimeImmutable $updatedAfter = null,
        public ?DateTimeImmutable $updatedBefore = null,
        public ?DateTimeImmutable $expiresAfter = null,
        public ?DateTimeImmutable $expiresBefore = null,
        public string $listingScope = 'both',
    ) {
    }

    /**
     * @brief Serialize for pagination URLs and hidden form fields, omitting sort params in neutral state.
     * @param void No input parameter.
     * @return array<string, mixed>
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function toQueryParams(): array
    {
        $out = [];
        if (!$this->isSortNeutral()) {
            $out['sort'] = $this->sortField;
            $out['dir'] = $this->sortDirection;
        }
        if ($this->searchQuery !== '') {
            $out['q'] = $this->searchQuery;
        }
        if ($this->filterPublic !== '') {
            $out['filter_public'] = $this->filterPublic;
        }
        foreach ($this->extensionFilters as $ext) {
            if ($ext !== '') {
                $out['ext'][] = $ext;
            }
        }
        if ($this->view !== '' && $this->view !== 'list') {
            $out['view'] = $this->view;
        }
        if ($this->filterHasGrant !== '') {
            $out['filter_has_grant'] = $this->filterHasGrant;
        }
        foreach ($this->granteeUserIds as $gid) {
            if ($gid > 0) {
                $out['grantee'][] = $gid;
            }
        }
        $this->appendDateParam($out, 'uploaded_after', $this->uploadedAfter);
        $this->appendDateParam($out, 'uploaded_before', $this->uploadedBefore);
        $this->appendDateParam($out, 'updated_after', $this->updatedAfter);
        $this->appendDateParam($out, 'updated_before', $this->updatedBefore);
        $this->appendDateParam($out, 'expires_after', $this->expiresAfter);
        $this->appendDateParam($out, 'expires_before', $this->expiresBefore);
        if ($this->listingScope !== '' && $this->listingScope !== 'both') {
            $out['listing_scope'] = $this->listingScope;
        }

        return $out;
    }

    /**
     * @brief Tell whether criteria is in neutral sorting state.
     * @param void No input parameter.
     * @return bool
     * @date 2026-04-29
     * @author Stephane H.
     */
    public function isSortNeutral(): bool
    {
        return $this->sortField === '' || $this->sortDirection === '';
    }

    /**
     * @brief Append a datetime-local compatible query fragment when set.
     * @param array<string, mixed> $out Output map reference.
     * @param string $key Query parameter name.
     * @param DateTimeImmutable|null $instant Optional instant.
     * @return void
     * @date 2026-04-27
     * @author Stephane H.
     */
    private function appendDateParam(array &$out, string $key, ?DateTimeImmutable $instant): void
    {
        if ($instant instanceof DateTimeImmutable) {
            $out[$key] = $instant->format('Y-m-d\TH:i');
        }
    }
}
