<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Request;

/**
 * @brief Resolve listing owner scope for files pages (user mode or admin godview mode).
 * @date 2026-05-04
 * @author Stephane H.
 */
final class FilesQueryScopeResolver
{
    /**
     * @brief Compute effective owner scope and admin context flags from the request.
     * @param Request $request Incoming HTTP request.
     * @param User $user Authenticated user.
     * @param bool $isAdmin Whether the caller has admin privileges.
     * @return array{
     *     adminContext: bool,
     *     viewScope: string,
     *     canonicalViewScope: string,
     *     subjectUserId: int|null,
     *     ownerUserId: int|null,
     *     ownerFilter: string,
     *     ownerQuery: string,
     *     ownerFallbackApplied: bool
     * }
     * @date 2026-05-04
     * @author Stephane H.
     */
    public function resolve(Request $request, User $user, bool $isAdmin): array
    {
        $routeName = (string) $request->attributes->get('_route', '');
        $routeForcesAdmin = $routeName === 'admin_files_index';
        $queryAdminContext = $request->query->getBoolean('admin_context');
        $adminContext = $isAdmin && ($routeForcesAdmin || $queryAdminContext);
        $ownerUserId = (int) $user->getId();
        $ownerFilter = '';
        $viewScope = 'owner';
        $ownerQuery = trim((string) $request->query->get('owner_query', ''));
        $ownerFallbackApplied = false;

        if ($adminContext) {
            $viewScopeRaw = strtolower(trim((string) $request->query->get('admin_view_scope', 'owner')));
            $viewScope = $viewScopeRaw === 'all' ? 'all' : 'owner';
            $ownerCandidate = (int) $request->query->get('owner', 0);

            if ($viewScope === 'all') {
                $ownerUserId = null;
                $ownerFilter = '';
            } else {
                if ($ownerCandidate > 0) {
                    $ownerUserId = $ownerCandidate;
                    $ownerFilter = (string) $ownerCandidate;
                } else {
                    $ownerFilter = (string) $ownerUserId;
                    $ownerFallbackApplied = true;
                }
            }
        }

        $canonicalViewScope = 'me';
        $subjectUserId = (int) $user->getId();
        $vsCanonical = strtolower(trim((string) $request->query->get('view_scope', '')));
        $subjectFromQuery = (int) $request->query->get('subject_user', 0);
        $ownerCandidate = (int) $request->query->get('owner', 0);

        if (!$adminContext) {
            $canonicalViewScope = 'me';
            $subjectUserId = (int) $user->getId();
        } elseif (\in_array($vsCanonical, ['me', 'user', 'all'], true)) {
            if ($vsCanonical === 'all') {
                $canonicalViewScope = 'all';
                $subjectUserId = null;
            } elseif ($vsCanonical === 'user') {
                $canonicalViewScope = 'user';
                $picked = $subjectFromQuery > 0 ? $subjectFromQuery : $ownerCandidate;
                $subjectUserId = $picked > 0 ? $picked : (int) $user->getId();
            } else {
                $canonicalViewScope = 'me';
                $subjectUserId = (int) $user->getId();
            }
        } elseif ($viewScope === 'all') {
            $canonicalViewScope = 'all';
            $subjectUserId = null;
        } elseif ($ownerCandidate > 0 && !$ownerFallbackApplied) {
            $canonicalViewScope = 'user';
            $subjectUserId = $ownerCandidate;
        } else {
            $canonicalViewScope = 'me';
            $subjectUserId = (int) $user->getId();
        }

        // Godview: admin_view_scope=all means multi-user panes; do not let a stale view_scope=me
        // (from prior navigation) block canonical "all". Explicit view_scope=user + target id keeps single-user drilldown.
        if ($adminContext && $viewScope === 'all') {
            $explicitUserDrilldown = $vsCanonical === 'user'
                && ($subjectFromQuery > 0 || ($ownerCandidate > 0 && !$ownerFallbackApplied));
            if (!$explicitUserDrilldown) {
                $canonicalViewScope = 'all';
                $subjectUserId = null;
            }
        }

        return [
            'adminContext' => $adminContext,
            'viewScope' => $viewScope,
            'canonicalViewScope' => $canonicalViewScope,
            'subjectUserId' => $subjectUserId,
            'ownerUserId' => $ownerUserId,
            'ownerFilter' => $ownerFilter,
            'ownerQuery' => $ownerQuery,
            'ownerFallbackApplied' => $ownerFallbackApplied,
        ];
    }
}
